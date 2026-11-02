/**
 * iProy · SDK JavaScript (Node.js ≥ 18) del Servidor PIM v1.0 (PIM v0.9 / HL7 FHIR R4) · 30-10-2026
 * Sin dependencias: usa fetch y node:crypto. Para gateways IoT, integraciones y scripts.
 *
 *   import { PimCliente } from './pim-cliente.mjs';
 *   const pim = new PimCliente({ base: 'https://nodo:8443/pim/r4', clientId: 'gateway-iot', clavePem: fs.readFileSync('clave_privada.pem', 'utf8'), kid: 'gw-1' });
 *   await pim.medir(deviceId, 'PRES-LINEA', 412.5, 'kPa');
 *
 * La clave privada nunca sale de tu equipo: el nodo solo conoce tu JWKS público y tu rol PIM.
 */
import { createPrivateKey, sign, randomBytes } from 'node:crypto';

export class PimError extends Error {
  constructor(http, mensaje, outcome) { super(mensaje); this.http = http; this.outcome = outcome; }
}

const NS = 'https://iproy.com.ar/fhir';
const b64u = (b) => Buffer.from(b).toString('base64url');

export class PimCliente {
  /** @param {{base:string, clientId:string, clavePem:string, kid?:string, alcance?:string}} o */
  constructor({ base, clientId, clavePem, kid = '', alcance = 'system/*.cruds' }) {
    this.base = base.replace(/\/+$/, '');
    this.clientId = clientId; this.kid = kid; this.alcance = alcance;
    this.clave = createPrivateKey(clavePem);
    this.alg = this.clave.asymmetricKeyType === 'ec' ? 'ES384' : 'RS384';
    this.tok = null; this.exp = 0;
  }

  // ------------------------------------------------------------ operaciones
  metadata() { return this.#http('GET', 'metadata', null, {}, false).then((r) => r.cuerpo); }
  leer(tipo, id) { return this.#http('GET', `${tipo}/${id}`).then((r) => r.cuerpo); }
  async buscar(tipo, params = {}, todas = false) {
    const q = new URLSearchParams(params).toString();
    let b = (await this.#http('GET', tipo + (q ? '?' + q : ''))).cuerpo;
    if (!todas) return b;
    const l = (b.entry || []).map((e) => e.resource);
    let n;
    while ((n = (b.link || []).find((x) => x.relation === 'next'))) {
      b = (await this.#http('GET', n.url.slice(this.base.length + 1))).cuerpo;
      for (const e of b.entry || []) l.push(e.resource);
    }
    return l;
  }
  crear(recurso) { return this.#http('POST', recurso.resourceType, recurso).then((r) => r.cuerpo); }
  actualizar(recurso) {
    const v = recurso.meta && recurso.meta.versionId;
    if (!v) throw new Error('Falta meta.versionId: leé el recurso antes de modificarlo');
    return this.#http('PUT', `${recurso.resourceType}/${recurso.id}`, recurso, { 'If-Match': `W/"${v}"` }).then((r) => r.cuerpo);
  }
  /** extra: { motivo, responsable: 'Organization/…', prioridad, tipoMantenimiento, modoFalla, nota } */
  transicion(taskId, estado, extra = {}, version = null) {
    const p = [{ name: 'estado', valueCode: estado }];
    for (const [k, v] of Object.entries(extra)) {
      if (v == null || v === '') continue;
      if (k === 'responsable') p.push({ name: k, valueReference: { reference: v } });
      else if (['prioridad', 'tipoMantenimiento', 'modoFalla'].includes(k)) p.push({ name: k, valueCode: v });
      else p.push({ name: k, valueString: v });
    }
    return this.#http('POST', `Task/${taskId}/$transicion`, { resourceType: 'Parameters', parameter: p }, version ? { 'If-Match': `W/"${version}"` } : {}).then((r) => r.cuerpo);
  }
  /** Devuelve { solicitud, orden: 'Task/<id>' } */
  async solicitar({ sujeto, categoria, descripcion, estadoDeclarado, prioridad, solicitante }) {
    const sr = { resourceType: 'ServiceRequest', status: 'active', intent: 'order', subject: { reference: sujeto },
      code: { text: categoria }, note: [{ text: descripcion }] };
    if (prioridad) sr.priority = prioridad;
    if (solicitante) sr.requester = { display: solicitante };
    if (estadoDeclarado) sr.extension = [{ url: `${NS}/StructureDefinition/pim-estado-declarado`, valueCoding: { system: `${NS}/CodeSystem/pim-valores`, code: estadoDeclarado } }];
    const r = await this.#http('POST', 'ServiceRequest', sr);
    return { solicitud: r.cuerpo, orden: r.cabeceras.get('x-pim-task') };
  }
  medir(deviceId, punto, valor, unidadUcum, instante = new Date().toISOString()) {
    return this.crear({ resourceType: 'Observation', status: 'final', device: { reference: `Device/${deviceId}` },
      code: { coding: [{ system: `${NS}/CodeSystem/pim-punto`, code: punto }] }, effectiveDateTime: instante,
      valueQuantity: { value: valor, unit: unidadUcum, system: 'http://unitsofmeasure.org', code: unidadUcum } });
  }
  lote(recursos) {
    return this.#http('POST', '', { resourceType: 'Bundle', type: 'batch',
      entry: recursos.map((r) => ({ resource: r, request: { method: 'POST', url: r.resourceType } })) }).then((r) => r.cuerpo);
  }
  historial(tipo, id = null, desde = null) {
    return this.#http('GET', `${tipo}${id ? '/' + id : ''}/_history${desde ? '?_since=' + encodeURIComponent(desde) : ''}`).then((r) => r.cuerpo);
  }
  procedencia(ref) { return this.buscar('Provenance', { target: ref }, true); }

  // ------------------------------------------------------------ SMART Backend Services
  async #token() {
    if (this.tok && this.exp > Date.now() / 1000 + 20) return this.tok;
    const aud = this.base.slice(0, -3) + '/auth/token';
    const h = b64u(JSON.stringify(Object.assign({ alg: this.alg, typ: 'JWT' }, this.kid ? { kid: this.kid } : {})));
    const p = b64u(JSON.stringify({ iss: this.clientId, sub: this.clientId, aud, exp: Math.floor(Date.now() / 1000) + 240, jti: randomBytes(16).toString('hex') }));
    const firma = sign('sha384', Buffer.from(`${h}.${p}`), this.alg === 'ES384' ? { key: this.clave, dsaEncoding: 'ieee-p1363' } : this.clave);
    const r = await fetch(aud, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({
      grant_type: 'client_credentials', client_assertion_type: 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
      client_assertion: `${h}.${p}.${b64u(firma)}`, scope: this.alcance }) });
    const j = await r.json().catch(() => ({}));
    if (!j.access_token) throw new PimError(r.status, 'Token rechazado: ' + (j.error_description || r.statusText));
    this.tok = j.access_token; this.exp = Date.now() / 1000 + j.expires_in;
    return this.tok;
  }
  async #http(metodo, ruta, cuerpo = null, cab = {}, auth = true) {
    const h = Object.assign({ Accept: 'application/fhir+json' }, cab);
    if (auth) h.Authorization = 'Bearer ' + (await this.#token());
    if (cuerpo) h['Content-Type'] = 'application/fhir+json';
    const r = await fetch(this.base + (ruta ? '/' + ruta : ''), { method: metodo, headers: h, body: cuerpo ? JSON.stringify(cuerpo) : undefined });
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new PimError(r.status, (j.issue && j.issue[0] && j.issue[0].diagnostics) || j.error_description || `HTTP ${r.status}`, j);
    return { cuerpo: j, cabeceras: r.headers };
  }
}
