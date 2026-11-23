// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo requerimientos · «Req. Mantenimiento», «Req. Ing. Clinica» y «Ver Sábanas», todo por la API PIM.
//   Instalación  → ServiceRequest sobre el Location del servicio, con la clase de sistema PIM (Anexo B) como código.
//   Equipo médico → ServiceRequest sobre el Device, con el motivo de la lista del nodo.
(() => {
  const { h, fecha, aviso, tabla, panel, cerrar, api, pim, puede, kpis, alClicKpi, csv, icono, form } = Nucleo;
  const $ = (s, r = document) => r.querySelector(s); const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const NS = 'https://iproy.com.ar/fhir';
  // familias de la clase de sistema (prefijo del código PIM) con el nombre que usa un servicio para pedir
  const FAM = { GAS: 'Gases medicinales y gas natural', ELE: 'Electricidad e iluminación', HVAC: 'Climatización', FIL: 'Filtración de aire', EST: 'Esterilización y vapor', AGU: 'Agua e instalaciones sanitarias',
    DES: 'Desagües', FRIO: 'Frío de conservación', INC: 'Incendio', TRV: 'Ascensores', COM: 'Comunicaciones', EDI: 'Edificio, aberturas y obra civil', PIS: 'Pisos', TIC: 'Sistemas y datos' };
  // estados PIM (Tabla 9-2) agrupados como los ve un servicio o una empresa
  const GRUPO = { solicitada: 'Pendiente', 'en-revision': 'Revisión', asignada: 'En Proceso', aceptada: 'En Proceso', lista: 'En Proceso', 'en-ejecucion': 'En Proceso', 'en-espera': 'Atención', finalizada: 'Finalizado', conformada: 'Finalizado', rechazada: 'Denegado', anulada: 'Denegado' };
  const GRUPOS = [['Finalizado', '#2e7d32', 'ok'], ['En Proceso', '#1565c0', 'reloj'], ['Atención', '#ef6c00', 'alarmas'], ['Revisión', '#6a1b9a', 'filtro'], ['Pendiente', '#546e7a', 'ordenes'], ['Denegado', '#b71c1c', 'candado']];
  const TXT = { solicitada: 'Solicitada', 'en-revision': 'En revisión', asignada: 'Asignada', aceptada: 'Aceptada', lista: 'Lista para ejecutar', 'en-ejecucion': 'En ejecución', 'en-espera': 'En espera', finalizada: 'Finalizada', conformada: 'Conformada', rechazada: 'Rechazada', anulada: 'Anulada' };
  const SIG = { solicitada: ['en-revision', 'asignada', 'rechazada', 'anulada'], 'en-revision': ['asignada', 'rechazada', 'anulada'], asignada: ['aceptada', 'en-revision', 'anulada'], aceptada: ['lista', 'en-ejecucion', 'en-espera', 'anulada'],
    lista: ['en-ejecucion', 'en-espera', 'anulada'], 'en-ejecucion': ['en-espera', 'finalizada'], 'en-espera': ['en-ejecucion', 'aceptada', 'anulada'], finalizada: ['conformada', 'en-ejecucion'] };
  const CON_MOTIVO = ['en-espera', 'rechazada', 'anulada', 'en-revision'];
  const est = (t) => t.businessStatus?.coding?.[0]?.code || t.status;
  const todo = async (ruta, tope = 2000) => { const o = []; let off = 0; for (;;) { const b = (await pim('GET', `${ruta}${ruta.includes('?') ? '&' : '?'}_count=200&_offset=${off}`)).cuerpo; const e = (b.entry || []).map((x) => x.resource); o.push(...e); off += e.length; if (!e.length || o.length >= (b.total ?? 0) || o.length >= tope) break; } return o; };
  const codLocal = (l) => (l?.identifier || []).find((i) => (i.system || '').endsWith('/sid/local'))?.value || '';
  const codActivo = (d) => (d?.identifier || []).find((i) => (i.system || '').endsWith('/sid/activo'))?.value || d?.identifier?.[0]?.value || '';
  const nomActivo = (d) => d.deviceName?.[0]?.name || d.modelNumber || d.id;
  /** Categoría de riesgo NFPA 99 del equipo o sistema (1 = la falla puede causar muerte o lesión grave … 4 = sin efecto sobre el paciente). */
  const riesgoDe = (d) => { const c = (d?.extension || []).find((x) => (x.url || '').endsWith('pim-categoria-riesgo'))?.valueCoding?.code; return c ? +c : null; };
  const etRiesgo = (n) => n ? `<span class="et ${n === 1 ? 'rojo' : n === 2 ? 'nar' : n === 3 ? 'ama' : 'gris'}" title="Categoría de riesgo NFPA 99">NFPA 99 · ${n}</span>` : '';
  const numCorto = (n) => String(n || '').replace(/^.*?-0*(\d+)$/, '$1');
  /** Código a la vista: número – servicio – (clase de sistema | código del equipo). El tercer tramo dice si es instalación o equipo médico. */
  const codigoOrden = (num, servicio, tercero) => [numCorto(num), servicio || 'S/S', tercero || 'S/C'].join('-');
  let cache = null;
  async function base() {
    if (cache) return cache;
    const [yo, locs, cs, L] = await Promise.all([api('GET', 'm/requerimientos/yo'), todo('Location'), pim('GET', 'CodeSystem/pim-sistema').then((r) => r.cuerpo.concept || []).catch(() => []), Nucleo.listas(['categoria_solicitud', 'tipo_mantenimiento'])]);
    const vals = (k) => (L[k]?.valores || []).filter((v) => v.activo !== 0);
    const servicios = locs.filter((l) => l.status !== 'inactive' && !(l.meta?.profile || []).some((p) => p.endsWith('pim-establecimiento'))).map((l) => ({ id: l.id, nombre: l.name, codigo: codLocal(l) })).sort((a, b) => a.nombre.localeCompare(b.nombre));
    cache = { yo, servicios, motivos: vals('categoria_solicitud'), tipos: vals('tipo_mantenimiento'), sistemas: cs.map((c) => ({ codigo: c.code, nombre: c.display || c.code, fam: c.code.split('-')[0] })) };
    setTimeout(() => { cache = null; }, 120000);   // dos minutos: un alta de servicio aparece sin reingresar
    return cache;
  }
  const opServicio = (B, v) => `<option value="">— Seleccione un servicio —</option>${B.servicios.map((s) => `<option value="${h(s.id)}" ${s.id === v ? 'selected' : ''}>${h(s.nombre)}</option>`).join('')}`;
  // PIM: en instalaciones el riesgo ante la falla es la categoría NFPA 99 del sistema (perfil pim-sistema). Para que llegue a la orden,
  // el pedido puede nombrar la instalación concreta (Device) además de la categoría.
  const esSistema = (d) => (d?.meta?.profile || []).some((x) => x.endsWith('pim-sistema'));
  const campoInstalacion = '<div class="fcampo" data-todo="1"><label>Instalación (opcional)</label><select name="instalacion" disabled><option value="">— Primero elegí la categoría —</option></select><small class="fayuda" data-ri></small></div>';
  const enlazarInstalacion = (f) => { const sc = f.categoria, si = f.instalacion; if (!sc || !si) return () => null; let lista = [];
    const nota = () => { const d = lista.find((x) => x.id === si.value); const n = f.querySelector('[data-ri]'); if (n) n.innerHTML = d ? (etRiesgo(riesgoDe(d)) || 'Sin categoría de riesgo cargada') : (lista.length ? 'Si sabés cuál es, elegila: la orden queda unida a esa instalación y a su categoría de riesgo.' : ''); };
    sc.onchange = async () => { si.disabled = true; si.innerHTML = '<option value="">Cargando…</option>'; lista = [];
      try { if (sc.value) lista = (await todo('Device?type=' + encodeURIComponent(sc.value), 400)).filter((d) => d.status === 'active' && esSistema(d)).sort((a, b) => codActivo(a).localeCompare(codActivo(b))); } catch (x) { lista = []; }
      si.innerHTML = `<option value="">${lista.length ? '— Toda la categoría —' : (sc.value ? '— No hay instalaciones cargadas en esta categoría —' : '— Primero elegí la categoría —')}</option>` + lista.map((d) => `<option value="${h(d.id)}">${h(codActivo(d))} · ${h(nomActivo(d))}</option>`).join('');
      si.disabled = !lista.length; nota(); };
    si.onchange = nota;
    return () => lista.find((x) => x.id === si.value) || null; };
  const opSistema = (B) => `<option value="">— Seleccione una categoría —</option>${Object.keys(FAM).filter((f) => B.sistemas.some((s) => s.fam === f)).map((f) => `<optgroup label="${h(FAM[f])}">${B.sistemas.filter((s) => s.fam === f).map((s) => `<option value="${h(s.codigo)}">${h(s.nombre)}</option>`).join('')}</optgroup>`).join('')}
    ${B.sistemas.filter((s) => !FAM[s.fam]).map((s) => `<option value="${h(s.codigo)}">${h(s.nombre)}</option>`).join('')}`;
  /** Bloque «servicio»: fijo si el usuario tiene uno asignado y no es transversal; si no, lo elige (y se recuerda el último). */
  const bloqueServicio = (B, etiqueta) => { const fijo = B.yo.servicio && !B.yo.transversal; const v = fijo ? B.yo.servicio : (B.yo.ultimo || B.yo.servicio || '');
    return fijo ? `<label>${etiqueta}</label><input value="${h(B.yo.servicio_nombre || '')}" disabled><input type="hidden" name="servicio" value="${h(B.yo.servicio)}">`
      : `<label>${etiqueta} <b class="req">*</b></label><select name="servicio" required>${opServicio(B, v)}</select>`; };
  const enviado = (el, r, cod, otra) => { el.innerHTML = `<div class="tarjeta" style="text-align:center"><div style="font-size:42px;color:#2e7d32">${icono('ok')}</div><h2>¡Solicitud enviada!</h2>
      <p>Orden <b>${h(r.orden || '—')}</b> · código <b>${h(cod)}</b></p><p class="mini">Solicitud ${h(r.solicitud)}. Podés seguirla en «Ver Sábanas».</p><div class="acciones" style="justify-content:center"><button class="b" id="rOtra">Nueva Solicitud</button></div></div>`;
    $('#rOtra', el).onclick = otra; };
  async function crear(sr, B, servicioId) {
    const r = await pim('POST', 'ServiceRequest', sr);
    if (!(B.yo.servicio && !B.yo.transversal)) api('PUT', 'm/requerimientos/yo/ultimo', { local_id: servicioId }).then(() => { if (cache) cache.yo.ultimo = servicioId; }).catch(() => {});
    // la cabecera X-PIM-Task trae la referencia (Task/<id>); el número a la vista se lee de la orden
    const ref = r.cab.get('X-PIM-Task') || ''; let orden = '';
    if (ref) { try { orden = (await pim('GET', ref)).cuerpo.identifier?.[0]?.value || ''; } catch (x) { /* el solicitante puede no tener lectura de órdenes: queda el número de solicitud */ } }
    return { solicitud: r.cuerpo.identifier?.[0]?.value || r.cuerpo.id, orden };
  }

  // ---------------------------------------------------------------- Req. Mantenimiento (instalaciones)
  Nucleo.pestana({ id: 'sg_requerimientos', titulo: 'Req. Mantenimiento', orden: 21, cap: 'pim.solicitar', grupo: 'mant', icono: 'solicitud', desc: 'Pedir un trabajo sobre una instalación', render: async function pintar(el) {
    const B = await base(); const u = Nucleo.sesion.usuario;
    el.innerHTML = `<div class="tarjeta"><h2>Req. Mantenimiento</h2><form class="form" id="rmF"><div class="fgrid">
      <div class="fcampo"><label>Nombre y apellido</label><input value="${h(u.nombre || u.usuario)}" disabled>${B.yo.transversal ? '<small class="fayuda">Transversal: podés pedir para cualquier servicio</small>' : ''}</div>
      <div class="fcampo">${bloqueServicio(B, 'Servicio')}</div>
      <div class="fcampo" data-todo="1"><label>Categoria <b class="req">*</b></label><select name="categoria" required>${opSistema(B)}</select></div>
      ${campoInstalacion}
      <div class="fcampo" data-todo="1"><label>Detalle del trabajo <b class="req">*</b></label><textarea name="detalle" required maxlength="5000" placeholder="Contá qué pasa y dónde."></textarea></div></div>
      <div class="acciones"><button class="b" type="submit">Enviar Pedido</button></div><div class="fmsg"></div></form></div>`;
    const instRM = enlazarInstalacion($('#rmF', el));
    $('#rmF', el).onsubmit = async (e) => { e.preventDefault(); const f = e.target; const m = $('.fmsg', f); const b = $('button[type=submit]', f);
      try { const s = B.servicios.find((x) => x.id === f.servicio.value); const c = B.sistemas.find((x) => x.codigo === f.categoria.value);
        if (!s) throw new Error('Elegí el servicio'); if (!c) throw new Error('Elegí la categoría');
        b.disabled = true;
        const di = instRM();
        const r = await crear({ resourceType: 'ServiceRequest', status: 'active', intent: 'order', ...(di ? { subject: { reference: 'Device/' + di.id, display: nomActivo(di) }, locationReference: [{ reference: 'Location/' + s.id }] } : { subject: { reference: 'Location/' + s.id, display: s.nombre } }),
          code: { coding: [{ system: NS + '/CodeSystem/pim-sistema', code: c.codigo, display: c.nombre }], text: c.nombre }, note: [{ text: f.detalle.value.trim() }], requester: { display: u.nombre || u.usuario } }, B, s.id);
        enviado(el, r, codigoOrden(r.orden, s.codigo, di ? codActivo(di) : c.codigo), () => pintar(el));
      } catch (x) { aviso(m, 'mal', x.message); } finally { b.disabled = false; } };
  } });

  // ---------------------------------------------------------------- Req. Ing. Clinica (equipo médico o instalación, excluyentes)
  Nucleo.pestana({ id: 'bio_nuevo_req', titulo: 'Req. Ing. Clinica', orden: 22, cap: 'pim.solicitar', grupo: 'mant', icono: 'inventario', desc: 'Equipo médico o instalación', render: async function pintar(el) {
    const B = await base(); const u = Nucleo.sesion.usuario; let modo = ''; let equipos = null; let elegido = null;
    el.innerHTML = `<div class="tarjeta"><h2>Req. Ing. Clinica</h2><form class="form" id="rbF"><div class="fgrid">
      <div class="fcampo"><label>Solicitante</label><input value="${h(u.nombre || u.usuario)}" disabled></div><div class="fcampo">${bloqueServicio(B, 'Servicio solicitante')}</div></div>
      <div class="kpis" id="rbM" style="margin:12px 0"></div><div id="rbC"></div>
      <div class="fcampo" data-todo="1"><label>Detalle del trabajo <b class="req">*</b></label><textarea name="detalle" required maxlength="5000"></textarea></div>
      <div class="acciones"><button class="b" type="submit">Enviar Pedido</button></div><div class="fmsg"></div></form></div>`;
    const f = $('#rbF', el); const c = $('#rbC', el);
    const pintaModo = () => { $('#rbM', el).innerHTML = kpis([{ t: 'Equipamiento médico PMA', n: '', sub: 'Un equipo del inventario', icono: 'inventario', color: '#c2185b', k: 'pma', activo: modo === 'pma' }, { t: 'Instalaciones Hospitalarias', n: '', sub: 'Un sistema del edificio', icono: 'modulo', color: '#1565c0', k: 'inst', activo: modo === 'inst' }]);
      alClicKpi($('#rbM', el), async (k) => { modo = k; elegido = null; pintaModo(); await pintaCuerpo(); }); };
    let instRB = () => null;
    const pintaCuerpo = async () => {
      if (modo === 'inst') { c.innerHTML = `<div class="fgrid"><div class="fcampo" data-todo="1"><label>Categoria <b class="req">*</b></label><select name="categoria" required>${opSistema(B)}</select></div>${campoInstalacion}</div>`; instRB = enlazarInstalacion(f); return; }
      if (modo !== 'pma') { c.innerHTML = '<p class="mini">Elegí arriba si el pedido es por un equipo médico o por una instalación.</p>'; return; }
      if (!equipos) { c.innerHTML = '<p class="mini">Cargando equipos…</p>'; equipos = (await todo('Device')).filter((d) => d.status === 'active'); }
      const conSrv = [...new Set(equipos.map((d) => d.location?.display).filter(Boolean))].sort();
      c.innerHTML = `<div class="fgrid"><div class="fcampo"><label>Equipo (PMA) <b class="req">*</b></label><input type="search" id="rbQ" placeholder="Código, equipo, marca, modelo o serie" autocomplete="off"></div>
        <div class="fcampo"><label>Dónde está</label><select id="rbS"><option value="">Todos los servicios (${equipos.length})</option>${conSrv.map((s) => `<option>${h(s)}</option>`).join('')}</select></div>
        <div class="fcampo" data-todo="1"><div id="rbL"></div></div>
        <div class="fcampo" data-todo="1"><label>Qué le pasa <b class="req">*</b></label><select name="motivo" required><option value="">— Elegí —</option>${B.motivos.map((v) => `<option value="${h(v.codigo)}">${h(v.texto)}</option>`).join('')}</select></div></div>`;
      const lista = () => { const t = $('#rbQ', c).value.trim().toLowerCase(); const s = $('#rbS', c).value;
        const l = equipos.filter((d) => (!s || d.location?.display === s) && (!t || [nomActivo(d), codActivo(d), d.manufacturer, d.modelNumber, d.serialNumber].filter(Boolean).join(' ').toLowerCase().includes(t))).slice(0, 12);
        $('#rbL', c).innerHTML = elegido ? `<p><span class="et verde">Elegido</span> <b>${h(nomActivo(elegido))}</b> · ${h(codActivo(elegido))} · ${h(elegido.location?.display || 'sin ubicación')} ${etRiesgo(riesgoDe(elegido))} <button type="button" class="b sec" id="rbX">✕ Cambiar</button></p>`
          : (l.length ? tabla([{ t: 'Código', v: (d) => codActivo(d) }, { t: 'Equipo', v: (d) => nomActivo(d) }, { t: 'Marca y modelo', v: (d) => [d.manufacturer, d.modelNumber].filter(Boolean).join(' ') }, { t: 'Serie', v: (d) => d.serialNumber || '' }, { t: 'Servicio', v: (d) => d.location?.display || '' }, { t: 'Riesgo', html: true, v: (d) => etRiesgo(riesgoDe(d)) }], l, (d) => { elegido = d; lista(); })
            : '<p class="mini">Ningún equipo coincide. Si no figura, pedí su alta en Inventario.</p>');
        const x = $('#rbX', c); if (x) x.onclick = () => { elegido = null; lista(); }; };
      $('#rbQ', c).oninput = lista; $('#rbS', c).onchange = lista; lista();
    };
    pintaModo(); await pintaCuerpo();
    f.onsubmit = async (e) => { e.preventDefault(); const m = $('.fmsg', f); const b = $('button[type=submit]', f);
      try { const s = B.servicios.find((x) => x.id === f.servicio.value); if (!s) throw new Error('Elegí el servicio solicitante'); if (!modo) throw new Error('Elegí si es un equipo médico o una instalación');
        const sr = { resourceType: 'ServiceRequest', status: 'active', intent: 'order', note: [{ text: f.detalle.value.trim() }], requester: { display: u.nombre || u.usuario } }; let tercero;
        if (modo === 'pma') { if (!elegido) throw new Error('Elegí el equipo de la lista'); const mo = B.motivos.find((v) => v.codigo === f.motivo.value); if (!mo) throw new Error('Elegí qué le pasa');
          sr.subject = { reference: 'Device/' + elegido.id, display: nomActivo(elegido) }; sr.locationReference = [{ reference: 'Location/' + s.id }]; sr.code = { coding: [{ code: mo.codigo }], text: mo.texto }; tercero = codActivo(elegido); }
        else { const ci = B.sistemas.find((x) => x.codigo === f.categoria.value); if (!ci) throw new Error('Elegí la categoría');
          const di = instRB(); if (di) { sr.subject = { reference: 'Device/' + di.id, display: nomActivo(di) }; sr.locationReference = [{ reference: 'Location/' + s.id }]; } else sr.subject = { reference: 'Location/' + s.id, display: s.nombre };
          sr.code = { coding: [{ system: NS + '/CodeSystem/pim-sistema', code: ci.codigo, display: ci.nombre }], text: ci.nombre }; tercero = di ? codActivo(di) : ci.codigo; }
        b.disabled = true; const r = await crear(sr, B, s.id); enviado(el, r, codigoOrden(r.orden, s.codigo, tercero), () => pintar(el));
      } catch (x) { aviso(m, 'mal', x.message); } finally { b.disabled = false; } };
  } });

  // ---------------------------------------------------------------- Ver Sábanas (órdenes por empresa)
  // La misma sábana, dos accesos: todas las órdenes («Ver Sábanas») y solo las de equipamiento médico («Ver Sábanas Bio»).
  for (const SB of [{ id: 'sg_sabanas_ver', titulo: 'Ver Sábanas', orden: 23, icono: 'ordenes', desc: 'Órdenes por empresa, con cambios en la fila', bio: false, archivo: 'sabana-ordenes' },
    { id: 'sabanas_bio', titulo: 'Ver Sábanas Bio', orden: 24, icono: 'inventario', desc: 'Órdenes de equipamiento médico por empresa', bio: true, archivo: 'sabana-bio' },
    { id: 'panol_ver_sabanas', titulo: 'Ver Sábanas', orden: 31, icono: 'stock', desc: 'Órdenes por empresa con lo entregado por el pañol', bio: false, archivo: 'sabana-panol', grupo: 'panol' }])
  Nucleo.pestana({ id: SB.id, titulo: SB.titulo, orden: SB.orden, cap: SB.grupo === 'panol' ? 'stock.ver' : 'pim.usar', grupo: SB.grupo || 'mant', icono: SB.icono, desc: SB.desc, render: async (el) => {
    const B = await base(); const POR = B.yo.cfg.por_pagina; const opera = puede('pim.ordenes.operar');
    const [orgs, devs] = await Promise.all([todo('Organization', 500), todo('Device')]); const tipos = B.tipos;
    const sisDev = Object.fromEntries(devs.map((d) => [d.id, esSistema(d)]));
    const codDev = Object.fromEntries(devs.map((d) => [d.id, codActivo(d)])); const rieDev = Object.fromEntries(devs.map((d) => [d.id, riesgoDe(d)])); const codSrv = Object.fromEntries(B.servicios.map((s) => [s.id, s.codigo]));
    const empresas = orgs.filter((o) => o.active !== false && !(o.meta?.profile || []).some((p) => p.endsWith('pim-organismo'))).map((o) => ({ id: o.id, nombre: o.name || o.id })).sort((a, b) => a.nombre.localeCompare(b.nombre));
    const F = { empresa: '', mes: '', meses: [], tipo: '', q: '', grupo: '' }; let T = []; let pag = 0;
    const mesOk = (t) => { const m = (t.authoredOn || '').slice(0, 7); return F.meses.length ? F.meses.includes(m) : (!F.mes || m === F.mes); };   // «Certificación»: varios meses juntos
    const tipoDe = (t) => (t.extension || []).find((x) => (x.url || '').endsWith('pim-tipo-mantenimiento'))?.valueCoding?.code || '';
    const ref = (r) => (r?.reference || '').split('/')[1] || '';
    const tercero = (t) => t.focus ? (codDev[ref(t.focus)] || 'S/C') : (t.code?.coding?.[0]?.code || 'S/C');
    const cod = (t) => codigoOrden(t.identifier?.[0]?.value, codSrv[ref(t.location)], tercero(t));
    const esEquipo = (t) => !!t.focus && !sisDev[ref(t.focus)];   // una orden puede nombrar una instalación concreta: sigue siendo de instalación
    el.innerHTML = `<div class="sab"><div class="tarjeta"><div style="display:flex;flex-wrap:wrap;gap:8px;align-items:end">
        <label style="flex:1 1 220px">Empresa: <select id="vE"><option value="">Seleccionar empresa…</option><option value="*">Todas las empresas</option><option value="-">— Sin empresa —</option>${empresas.map((o) => `<option value="${h(o.id)}">${h(o.nombre)}</option>`).join('')}</select></label>
        <select id="vM" style="flex:0 1 170px;width:auto"><option value="">Todos los meses</option></select>
        <details class="vCert" style="position:relative"><summary class="b sec" title="Elegir varios meses (para certificar)">Certificación ▾</summary><div id="vMM" class="tarjeta" style="position:absolute;z-index:20;min-width:200px;max-height:260px;overflow:auto;margin-top:4px"></div></details>
        <select id="vT" style="flex:0 1 170px;width:auto"><option value="">Tipo de Tarea</option>${tipos.map((v) => `<option value="${h(v.codigo)}">${h(v.texto)}</option>`).join('')}</select>
        <input id="vQ" type="search" placeholder="🔍 Buscar…" style="flex:1 1 180px;width:auto"><button class="b sec" id="vL">Limpiar</button><button class="b sec" id="vX">${icono('bajar')} Excel</button>${opera ? `<button class="b sec" id="vR">${icono('usuarios')} Operarios</button>` : ''}</div>
      <div id="vK" style="margin-top:10px"></div><div id="vC"><p class="mini">Seleccioná una empresa arriba para cargar su sábana de órdenes.</p></div><div id="vMsg"></div></div></div>`;
    const verPanol = puede('reportes.empresa');   // nota de pañol: insumos entregados a la orden (copia del módulo reportes)
    const codOrg = Object.fromEntries(orgs.map((o) => [o.id, (o.identifier || []).find((i) => (i.system || '').endsWith('/sid/empresa'))?.value || o.identifier?.[0]?.value || '']));
    const dur = (m) => m == null ? '' : m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')} min`;
    async function operariosDe(t) { const org = codOrg[ref(t.owner)]; const num = t.identifier?.[0]?.value; if (!org) return aviso($('#vMsg', el), 'mal', 'La empresa de esta orden no tiene código.');
      const p = panel('Operarios · orden ' + numCorto(num), '<div id="opC"><p class="mini">Cargando…</p></div>'); const c = p.querySelector('#opC');
      const pintaO = async (msg) => { let ops, tr; try { [ops, tr] = await Promise.all([api('GET', 'm/requerimientos/operarios?org=' + encodeURIComponent(org)).then((r) => r.operarios), api('GET', 'm/requerimientos/trabajos?ordenes=' + t.id).then((r) => r.trabajos)]); } catch (x) { c.innerHTML = `<div class="aviso mal">${h(x.message)}</div>`; return; }
        const libres = ops.filter((o) => !tr.some((w) => w.operario_id === o.id));
        c.innerHTML = `<p class="mini">${h(t.owner?.display || '')} · ${h(cod(t))}</p>${tabla([{ t: 'Operario', v: (w) => w.operario }, { t: 'Inicio', v: (w) => w.inicio ? fecha(w.inicio) : '—' }, { t: 'Fin', v: (w) => w.fin ? fecha(w.fin) : '—' }, { t: 'Duración', v: (w) => dur(w.minutos) },
            { t: '', html: true, v: (w) => `${!w.inicio ? `<button class="b sec opI" data-id="${w.id}">Iniciar</button> ` : ''}${w.inicio && !w.fin ? `<button class="b opF" data-id="${w.id}">Terminar</button> ` : ''}<button class="b sec opQ" data-id="${w.id}" title="Quitar de la orden">✕</button>` }], tr)}
          <h3>Sumar un operario</h3><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><select id="opS" style="flex:1 1 200px;width:auto"><option value="">— Elegí —</option>${libres.map((o) => `<option value="${o.id}">${h(o.nombre)}</option>`).join('')}</select>
            <label class="fchk" style="margin:0"><input type="checkbox" id="opAh" checked> empieza ahora</label><button class="b" id="opA">Sumar</button></div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px"><input id="opN" maxlength="80" placeholder="Operario nuevo de ${h(t.owner?.display || 'la empresa')}" style="flex:1 1 200px;width:auto"><button class="b sec" id="opNG">Agregar a la lista</button></div><div id="opM"></div>`;
        const m = c.querySelector('#opM'); if (msg) aviso(m, msg[0], msg[1]);
        const hacer = async (f, ok) => { try { await f(); await pintaO(['ok', ok]); pinta(); } catch (x) { aviso(m, 'mal', x.message); } };
        c.querySelector('#opA').onclick = () => { const id = +c.querySelector('#opS').value; if (!id) return aviso(m, 'mal', 'Elegí un operario de la lista.'); hacer(() => api('POST', 'm/requerimientos/trabajos', { orden: t.id, numero: num, operario_id: id, ...(c.querySelector('#opAh').checked ? { inicio: 'ahora' } : {}) }), 'Operario sumado a la orden.'); };
        c.querySelector('#opNG').onclick = () => { const n = c.querySelector('#opN').value.trim(); if (!n) return aviso(m, 'mal', 'Escribí el nombre.'); hacer(() => api('POST', 'm/requerimientos/operarios', { org, nombre: n }), 'Operario agregado a la lista de la empresa.'); };
        c.querySelectorAll('.opI').forEach((b) => b.onclick = () => hacer(() => api('PUT', 'm/requerimientos/trabajos/' + b.dataset.id, { inicio: 'ahora' }), 'Inicio registrado.'));
        c.querySelectorAll('.opF').forEach((b) => b.onclick = () => hacer(() => api('PUT', 'm/requerimientos/trabajos/' + b.dataset.id, { fin: 'ahora' }), 'Fin registrado.'));
        c.querySelectorAll('.opQ').forEach((b) => b.onclick = () => hacer(() => api('POST', `m/requerimientos/trabajos/${b.dataset.id}/borrar`, {}), 'Operario quitado de la orden.')); };
      await pintaO(); }
    async function reporteOperarios() { const org = F.empresa && F.empresa !== '*' && F.empresa !== '-' ? codOrg[F.empresa] : ''; let r; try { r = await api('GET', 'm/requerimientos/operarios/reporte' + (org ? '?org=' + encodeURIComponent(org) : '')); } catch (x) { return aviso($('#vMsg', el), 'mal', x.message); }
      const p = panel('Operarios · últimos 30 días', `<p class="mini">${org ? h(empresas.find((e) => e.id === F.empresa)?.nombre || org) : 'Todas las empresas'} · del ${h(r.desde)} al ${h(r.hasta)}</p><h3>Por operario</h3>
        ${tabla([{ t: 'Operario', v: (o) => o.operario }, { t: 'Empresa', v: (o) => o.org }, { t: 'Órdenes', v: (o) => o.ordenes }, { t: 'Tiempo total', v: (o) => dur(o.minutos) }, { t: 'Promedio por orden', v: (o) => dur(o.promedio) }], r.por_operario)}
        <h3>Registro de trabajos (${r.registro.length})</h3>${tabla([{ t: 'Orden', v: (w) => numCorto(w.numero) }, { t: 'Operario', v: (w) => w.operario }, { t: 'Inicio', v: (w) => w.inicio ? fecha(w.inicio) : '—' }, { t: 'Fin', v: (w) => w.fin ? fecha(w.fin) : '—' }, { t: 'Duración', v: (w) => dur(w.minutos) }], r.registro.slice(0, 200))}
        <div class="acciones"><button class="b sec" id="roX">${icono('bajar')} Excel</button></div>`);
      p.querySelector('#roX').onclick = () => csv('operarios', [{ t: 'Orden', v: (w) => numCorto(w.numero) }, { t: 'Número PIM', v: (w) => w.numero }, { t: 'Empresa', v: (w) => w.org }, { t: 'Operario', v: (w) => w.operario }, { t: 'Inicio', v: (w) => w.inicio || '' }, { t: 'Fin', v: (w) => w.fin || '' }, { t: 'Minutos', v: (w) => w.minutos ?? '' }], r.registro); }
    const filtradas = () => T.filter((t) => mesOk(t) && (!F.tipo || tipoDe(t) === F.tipo) && (!F.grupo || GRUPO[est(t)] === F.grupo)
      && (!F.q || [t.identifier?.[0]?.value, cod(t), t.location?.display, t.focus?.display, t.description, t.code?.text, t.owner?.display].filter(Boolean).join(' ').toLowerCase().includes(F.q)));
    const pinta = () => {
      const sinG = T.filter((t) => mesOk(t) && (!F.tipo || tipoDe(t) === F.tipo)); const cta = {}; sinG.forEach((t) => { const g = GRUPO[est(t)]; cta[g] = (cta[g] || 0) + 1; });
      $('#vK', el).innerHTML = kpis([...GRUPOS.map(([g, color, ic]) => ({ t: g, n: cta[g] || 0, k: g, color, icono: ic, activo: F.grupo === g })), { t: 'Total', n: sinG.length, k: '__t', color: '#37474f', icono: 'ordenes', activo: !F.grupo }]);
      alClicKpi($('#vK', el), (k) => { F.grupo = k === '__t' ? '' : k; pag = 0; pinta(); });
      const l = filtradas(); const pags = Math.max(1, Math.ceil(l.length / POR)); pag = Math.min(pag, pags - 1); const ver = l.slice(pag * POR, pag * POR + POR);
      const sinDet = l.length && !l.some((t) => t.description);
      $('#vC', el).innerHTML = `${sinDet ? '<p class="mini">El detalle escrito de los pedidos está guardado pero no se muestra: se activa en Configuración › Migración › Ajustes.</p>' : ''}${tabla([{ t: 'Orden', html: true, v: (t) => `<b class="nord">${h(numCorto(t.identifier?.[0]?.value))}</b><br><small class="mini">${h(cod(t))}</small>` }, { t: 'Fecha', v: (t) => fecha(t.authoredOn) },
          { t: 'Servicio', v: (t) => t.location?.display || '' }, { t: 'Detalle', html: true, v: (t) => `<span class="et ${esEquipo(t) ? 'ama' : ''}">${esEquipo(t) ? 'Equipo' : 'Instalación'}</span> ${h(t.focus?.display || t.code?.text || '')} ${t.focus ? etRiesgo(rieDev[ref(t.focus)]) : ''}<br><small>${h((t.description || '').slice(0, 140))}</small>` },
          { t: 'Tipo de tarea', html: true, v: (t) => opera && SIG[est(t)] ? `<select class="vt" data-id="${t.id}"><option value="">—</option>${tipos.map((v) => `<option value="${h(v.codigo)}" ${tipoDe(t) === v.codigo ? 'selected' : ''}>${h(v.texto)}</option>`).join('')}</select>` : h(Nucleo.textoLista('tipo_mantenimiento', tipoDe(t)) || '') },
          { t: 'Estado', html: true, v: (t) => { const e = est(t); return opera && SIG[e] ? `<select class="ve" data-id="${t.id}"><option value="">${h(TXT[e] || e)}</option>${SIG[e].map((x) => `<option value="${x}">→ ${h(TXT[x])}</option>`).join('')}</select>` : `<span class="et">${h(TXT[e] || e)}</span>`; } },
          { t: 'Anotación', html: true, v: (t) => opera && SIG[est(t)] ? `<input class="vn" data-id="${t.id}" placeholder="Anotar…" maxlength="255">` : h(t.statusReason?.text || '') },
          ...(verPanol ? [{ t: 'Pañol', html: true, v: (t) => `<span class="vp" data-id="${t.id}"></span>` }] : []),
          ...(opera ? [{ t: 'Operarios', html: true, v: (t) => t.owner ? `<span class="vo" data-id="${t.id}"></span> <button class="b sec vob" data-id="${t.id}" title="Operarios de la orden">${icono('usuarios')}</button>` : '<span class="mini">sin empresa</span>' }] : []),
          { t: 'Empresa', v: (t) => t.owner?.display || '—' }, { t: '', html: true, v: (t) => `<button class="b sec vimp" data-id="${t.id}" title="Imprimir la orden">${icono('imprimir')}</button>` }], ver)}
        <p class="mini">${l.length ? `${pag * POR + 1}–${pag * POR + ver.length} · ${l.length} órdenes · pág. ${pag + 1}/${pags}` : 'Sin órdenes con esos filtros'}
          ${pags > 1 ? ` · <button class="b sec" id="vA" ${pag ? '' : 'disabled'}>Página anterior</button> <button class="b sec" id="vS" ${pag < pags - 1 ? '' : 'disabled'}>Página siguiente</button>` : ''}</p>`;
      if (opera && ver.some((t) => t.owner)) api('GET', 'm/requerimientos/trabajos?ordenes=' + ver.filter((t) => t.owner).map((t) => t.id).join(',')).then((r) => { const por = {}; for (const w of r.trabajos) (por[w.orden] = por[w.orden] || []).push(w);
        $$('.vo', el).forEach((c) => { const l = por[c.dataset.id] || []; c.innerHTML = l.map((w) => `<span class="et ${w.fin ? 'verde' : w.inicio ? 'ama' : ''}" title="${w.fin ? 'Terminó' : w.inicio ? 'Trabajando' : 'Asignado'}">${h(w.operario)}</span>`).join(' '); }); }).catch(() => null);
      $$('.vimp', el).forEach((b) => b.onclick = () => { const t = T.find((x) => x.id === b.dataset.id); const e = est(t); document.querySelectorAll('.soloimp').forEach((x) => x.remove()); const f = (a, v) => `<tr><th>${a}</th><td>${v || '&nbsp;'}</td></tr>`;
        const d = document.createElement('div'); d.className = 'imprimible soloimp'; const ops = $(`.vo[data-id="${t.id}"]`, el)?.innerText.trim() || '';
        d.innerHTML = `<h1>Orden de trabajo ${h(numCorto(t.identifier?.[0]?.value))}</h1><div>${h(Nucleo.sesion?.nodo?.nombre || '')} · ${h(cod(t))} · impresa el ${fecha(new Date().toISOString())}</div>
          <table>${f('Estado', h(GRUPO[e] || '') + ' · ' + h(TXT[e] || e) + (t.statusReason?.text ? ' · ' + h(t.statusReason.text) : ''))}${f('Servicio', h(t.location?.display || ''))}${f(esEquipo(t) ? 'Equipo médico' : 'Instalación', h(t.focus?.display || t.code?.text || '') + (t.focus && codDev[ref(t.focus)] ? ' · ' + h(codDev[ref(t.focus)]) : ''))}
          ${t.focus && rieDev[ref(t.focus)] ? f('Categoría de riesgo NFPA 99', String(rieDev[ref(t.focus)])) : ''}${f('Detalle', h(t.description || '').replace(/\n/g, '<br>'))}${f('Empresa o área', h(t.owner?.display || ''))}${f('Operarios', h(ops))}${f('Pañol', h($(`.vp[data-id="${t.id}"]`, el)?.innerText.trim() || ''))}
          ${f('Tipo', h(Nucleo.textoLista('tipo_mantenimiento', tipoDe(t)) || ''))}${f('Creada', fecha(t.authoredOn))}${f('Inicio', t.executionPeriod?.start ? fecha(t.executionPeriod.start) : '')}${f('Finalizada', t.executionPeriod?.end ? fecha(t.executionPeriod.end) : '')}</table>
          <table>${f('Trabajo realizado', '<br><br><br>')}${f('Materiales', '<br><br>')}${f('Observaciones', '<br><br>')}</table><div class="firmas"><div>Realizó</div><div>Conformidad del sector</div><div>Supervisó</div></div>`;
        document.body.appendChild(d); window.print(); });
      if (verPanol && ver.length) api('GET', 'm/reportes/insumos?ordenes=' + ver.map((t) => t.id).join(',')).then((r) => {
        $$('.vp', el).forEach((c) => { const l = r.insumos[c.dataset.id] || []; c.innerHTML = l.length ? l.map((x) => `<span class="et gris" title="${h([x.descripcion, x.observacion].filter(Boolean).join(' · '))}">${h(String(x.cantidad).replace('.', ','))} ${h(x.unidad || '')} ${h(x.codigo)}</span>`).join(' ') : ''; }); }).catch(() => null);
      $$('.vob', el).forEach((b) => b.onclick = () => operariosDe(T.find((x) => x.id === b.dataset.id)));
      const a = $('#vA', el); if (a) a.onclick = () => { pag--; pinta(); }; const s = $('#vS', el); if (s) s.onclick = () => { pag++; pinta(); };
      const aplicar = async (id, par, ok) => { const t = T.find((x) => x.id === id);
        try { const r = await pim('POST', `Task/${id}/$transicion`, { resourceType: 'Parameters', parameter: par }, { 'If-Match': `W/"${t.meta.versionId}"` }); Object.assign(t, r.cuerpo?.resourceType === 'Task' ? r.cuerpo : (await pim('GET', 'Task/' + id)).cuerpo);
          aviso($('#vMsg', el), 'ok', `Orden ${numCorto(t.identifier?.[0]?.value)}: ${ok}.`); }
        catch (x) { aviso($('#vMsg', el), 'mal', x.message); }
        pinta(); };
      $$('.ve', el).forEach((x) => x.onchange = () => { if (!x.value) return; const nota = $(`.vn[data-id="${x.dataset.id}"]`, el)?.value.trim() || '';
        if (CON_MOTIVO.includes(x.value) && !nota) { aviso($('#vMsg', el), 'mal', `Para pasar a «${TXT[x.value]}» escribí el motivo en «Anotar…» de esa fila.`); x.value = ''; return; }
        const par = [{ name: 'estado', valueCode: x.value }]; if (nota) par.push({ name: 'motivo', valueString: nota }); const tt = $(`.vt[data-id="${x.dataset.id}"]`, el)?.value; if (tt) par.push({ name: 'tipoMantenimiento', valueCode: tt });
        aplicar(x.dataset.id, par, `pasó a «${TXT[x.value]}»`); });
      $$('.vt', el).forEach((x) => x.onchange = () => { if (x.value) aplicar(x.dataset.id, [{ name: 'tipoMantenimiento', valueCode: x.value }], 'tipo de tarea guardado'); });
    };
    const leer = async () => { if (!F.empresa) { T = []; $('#vK', el).innerHTML = ''; $('#vC', el).innerHTML = '<p class="mini">Seleccioná una empresa arriba para cargar su sábana de órdenes.</p>'; return; }
      $('#vC', el).innerHTML = '<p class="mini">Cargando…</p>';
      const l = await todo('Task?_sort=-authored-on' + (F.empresa !== '*' && F.empresa !== '-' ? '&owner=Organization/' + encodeURIComponent(F.empresa) : ''), B.yo.cfg.max_ordenes);
      T = (F.empresa === '-' ? l.filter((t) => !t.owner) : l).filter((t) => !SB.bio || esEquipo(t));
      const meses = [...new Set(T.map((t) => (t.authoredOn || '').slice(0, 7)).filter(Boolean))].sort().reverse();
      $('#vM', el).innerHTML = `<option value="">Todos los meses</option>${meses.map((m) => `<option value="${m}" ${F.mes === m ? 'selected' : ''}>${new Date(m + '-15').toLocaleDateString('es-AR', { month: 'short', year: 'numeric' })}</option>`).join('')}`;
      $('#vMM', el).innerHTML = meses.length ? meses.map((m) => `<label class="fchk" style="display:flex;margin:2px 0"><input type="checkbox" value="${m}" ${F.meses.includes(m) ? 'checked' : ''}> ${new Date(m + '-15').toLocaleDateString('es-AR', { month: 'long', year: 'numeric' })}</label>`).join('') : '<p class="mini">Sin meses</p>';
      $$('#vMM input', el).forEach((x) => x.onchange = () => { F.meses = $$('#vMM input:checked', el).map((y) => y.value); F.mes = ''; $('#vM', el).value = ''; $('#vM', el).disabled = !!F.meses.length;
        $('.vCert summary', el).textContent = F.meses.length ? `Certificación (${F.meses.length}) ▾` : 'Certificación ▾'; pag = 0; pinta(); });
      pag = 0; pinta(); };
    $('#vE', el).onchange = (e) => { F.empresa = e.target.value; F.mes = ''; F.meses = []; F.grupo = ''; $('#vM', el).disabled = false; $('.vCert summary', el).textContent = 'Certificación ▾'; leer(); };
    $('#vM', el).onchange = (e) => { F.mes = e.target.value; pag = 0; pinta(); }; $('#vT', el).onchange = (e) => { F.tipo = e.target.value; pag = 0; pinta(); };
    let tm; $('#vQ', el).oninput = (e) => { clearTimeout(tm); tm = setTimeout(() => { F.q = e.target.value.trim().toLowerCase(); pag = 0; pinta(); }, 250); };
    $('#vL', el).onclick = () => { Object.assign(F, { mes: '', meses: [], tipo: '', q: '', grupo: '' }); $('#vM', el).disabled = false; $('.vCert summary', el).textContent = 'Certificación ▾'; $$('#vMM input', el).forEach((x) => { x.checked = false; }); ['#vM', '#vT', '#vQ'].forEach((s) => { $(s, el).value = ''; }); pag = 0; if (T.length) pinta(); };
    if ($('#vR', el)) $('#vR', el).onclick = reporteOperarios;
    $('#vX', el).onclick = () => csv(SB.archivo, [{ t: 'Orden', v: (t) => numCorto(t.identifier?.[0]?.value) }, { t: 'Código', v: cod }, { t: 'Número PIM', v: (t) => t.identifier?.[0]?.value }, { t: 'Fecha', v: (t) => (t.authoredOn || '').slice(0, 10) }, { t: 'Servicio', v: (t) => t.location?.display || '' },
      { t: 'Clase', v: (t) => esEquipo(t) ? 'Equipo médico' : 'Instalación' }, { t: 'Riesgo NFPA 99', v: (t) => (t.focus && rieDev[ref(t.focus)]) || '' }, { t: 'Sobre', v: (t) => t.focus?.display || t.code?.text || '' }, { t: 'Detalle', v: (t) => t.description || '' }, { t: 'Tipo de tarea', v: (t) => Nucleo.textoLista('tipo_mantenimiento', tipoDe(t)) || '' },
      { t: 'Estado', v: (t) => TXT[est(t)] || est(t) }, { t: 'Grupo', v: (t) => GRUPO[est(t)] }, { t: 'Empresa', v: (t) => t.owner?.display || '' }], filtradas());
    if (!puede('pim.ordenes.operar') && empresas.length === 0) { F.empresa = '*'; $('#vE', el).value = '*'; await leer(); }
  } });

  // ---------------------------------------------------------------- Configuración › Requerimientos: el servicio de cada usuario
  Nucleo.configuracion({ id: 'requerimientos', titulo: 'Requerimientos', render: async (c) => {
    const [B, j] = await Promise.all([base(), api('GET', 'm/requerimientos/usuarios')]);
    c.innerHTML = `<div class="tarjeta"><h2>Servicio de cada usuario</h2><p class="mini">Con un servicio asignado, los pedidos de esa persona salen siempre a nombre de ese servicio. Sin servicio, o con la función «transversal», lo elige en cada pedido y el sistema recuerda el último.</p>
      ${tabla([{ t: 'Usuario', v: (u) => u.usuario }, { t: 'Nombre', v: (u) => u.nombre }, { t: 'Rol', v: (u) => u.rol }, { t: 'Servicio', html: true, v: (u) => `<select class="rU" data-u="${h(u.usuario)}"><option value="">— Lo elige al pedir —</option>${B.servicios.map((s) => `<option value="${h(s.id)}" ${u.local_id === s.id ? 'selected' : ''}>${h(s.nombre)}</option>`).join('')}</select>` }], j.usuarios.filter((u) => u.activo))}<div id="rUm"></div></div>
      <div class="tarjeta"><h2>Código de la orden</h2><p class="mini">Se arma solo: <b>número – código del servicio – tercer tramo</b>. En una instalación el tercer tramo es la clase de sistema PIM (por ejemplo GAS-O2, ELE-EMER); en un equipo médico es el código del equipo en el inventario. Así el código dice a simple vista si la orden es de instalaciones o de equipamiento. El número oficial de la orden sigue siendo el de PIM.</p></div>`;
    $$('.rU', c).forEach((s) => s.onchange = async () => { const sv = B.servicios.find((x) => x.id === s.value);
      try { await api('PUT', `m/requerimientos/usuarios/${encodeURIComponent(s.dataset.u)}/servicio`, { local_id: s.value, local_nombre: sv?.nombre || '' }); aviso($('#rUm', c), 'ok', `Servicio de ${s.dataset.u} guardado.`); cache = null; } catch (x) { aviso($('#rUm', c), 'mal', x.message); } });
  } });
})();
