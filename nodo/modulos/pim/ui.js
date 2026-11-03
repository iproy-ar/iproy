// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo pim · pestañas Órdenes, Nueva solicitud e Inventario (todo por la API PIM con el rol del usuario).
(() => {
  const { h, fecha, aviso, tabla, panel, form, pim, puede } = Nucleo;
  const PRIO = { stat: ['rojo', 'Inmediata'], asap: ['nar', 'Lo antes posible'], urgent: ['ama', 'Urgente'], routine: ['', 'Rutina'] };
  const prio = (p) => `<span class="et ${(PRIO[p] || [''])[0]}">${h((PRIO[p] || [0, p])[1])}</span>`;
  const est = (t) => t.businessStatus?.coding?.[0]?.code || t.status;
  const SIG = { solicitada: ['en-revision', 'asignada', 'rechazada', 'anulada'], 'en-revision': ['asignada', 'rechazada', 'anulada'], asignada: ['aceptada', 'en-revision', 'anulada'],
    aceptada: ['lista', 'en-ejecucion', 'en-espera', 'anulada'], lista: ['en-ejecucion', 'en-espera', 'anulada'], 'en-ejecucion': ['en-espera', 'finalizada'],
    'en-espera': ['en-ejecucion', 'aceptada', 'anulada'], finalizada: ['conformada', 'en-ejecucion'] };

  Nucleo.tablero(async (el) => {
    const c = async (q) => (await pim('GET', 'Task?' + q + '&_summary=count')).cuerpo.total;
    const [total, abiertas, urg, ejec, fin] = await Promise.all([c(''), c('status=requested,received,accepted,ready,in-progress,on-hold'),
      c('priority=stat,asap&status=requested,received,accepted,ready,in-progress'), c('status=in-progress'), c('status=completed')]);
    el.innerHTML = Nucleo.kpis([{ t: 'Órdenes totales', n: total, sub: 'histórico', icono: 'ordenes', color: '#1e88e5' },
      { t: 'Abiertas', n: abiertas, sub: 'sin cerrar', icono: 'reloj', color: '#fb8c00' },
      { t: 'Urgentes', n: urg, sub: 'inmediatas o ASAP abiertas', icono: 'alarmas', color: '#e53935' },
      { t: 'En ejecución', n: ejec, sub: 'trabajando ahora', icono: 'telemetria', color: '#8e24aa' },
      { t: 'Finalizadas', n: fin, sub: 'total', icono: 'ok', color: '#43a047' }]);
    el.querySelectorAll('.kpi').forEach((k) => { k.style.cursor = 'pointer'; k.onclick = () => Nucleo.ir('ordenes'); });
  }, { id: 'pim.ordenes', titulo: 'Órdenes de trabajo' });

  Nucleo.pestana({ id: 'ordenes', titulo: 'Órdenes', orden: 10, cap: 'pim.usar', render: async (el) => {
    el.innerHTML = `<div class="tarjeta"><div class="filtros"><div style="min-width:200px"><label>Estado</label><select id="oEst"><option value="">Abiertas</option>
      ${Object.keys(SIG).concat(['conformada', 'rechazada', 'anulada']).map((e) => `<option>${e}</option>`).join('')}<option value="*">Todas</option></select></div></div><div id="oLista"></div></div>`;
    const cargar = async () => {
      const e = document.getElementById('oEst').value;
      const q = e === '' ? 'status=requested,received,accepted,ready,in-progress,on-hold' : e === '*' ? '' : 'business-status=' + e;
      const b = (await pim('GET', 'Task?' + q + '&_count=100&_sort=-authored-on')).cuerpo;
      const l = (b.entry || []).map((x) => x.resource);
      document.getElementById('oLista').innerHTML = tabla([{ t: 'Número', v: (t) => t.identifier?.[0]?.value || t.id }, { t: 'Estado', html: true, v: (t) => `<span class="et">${h(est(t))}</span>` },
        { t: 'Prioridad', html: true, v: (t) => prio(t.priority) }, { t: 'Activo', v: (t) => t.focus?.display || '' }, { t: 'Responsable', v: (t) => t.owner?.display || '' },
        { t: 'Descripción', v: (t) => (t.description || '').slice(0, 90) }, { t: 'Alta', v: (t) => fecha(t.authoredOn) }], l, detalle);
    };
    document.getElementById('oEst').onchange = cargar; await cargar();
  } });
  async function detalle(t0) {
    const t = (await pim('GET', 'Task/' + t0.id)).cuerpo; const e = est(t);
    const p = panel('Orden ' + (t.identifier?.[0]?.value || t.id), `<dl><dt>Estado</dt><dd>${h(e)}${t.statusReason?.text ? ' · ' + h(t.statusReason.text) : ''}</dd><dt>Prioridad</dt><dd>${prio(t.priority)}</dd>
      <dt>Activo</dt><dd>${h(t.focus?.display || '—')}</dd><dt>Responsable</dt><dd>${h(t.owner?.display || '—')}</dd><dt>Descripción</dt><dd style="white-space:pre-wrap">${h(t.description || '')}</dd>
      <dt>Versión</dt><dd>${h(t.meta?.versionId)} · ${fecha(t.meta?.lastUpdated)}</dd></dl>
      ${puede('pim.ordenes.operar') && SIG[e] ? `<h3>Cambiar estado</h3>${form([{ n: 'estado', t: 'Nuevo estado', tipo: 'select', op: SIG[e].map((x) => [x, x]) },
        { n: 'responsable', t: 'Responsable (código de la organización, al asignar)' }, { n: 'tipoMantenimiento', t: 'Tipo (EN 13306)', tipo: 'select', op: [['', '—'], ['correctivo-inmediato', 'correctivo inmediato'], ['correctivo-diferido', 'correctivo diferido'], ['preventivo-sistematico', 'preventivo sistemático'], ['preventivo-condicional', 'preventivo condicional'], ['mejora', 'mejora'], ['ensayo-reglamentario', 'ensayo reglamentario']] },
        { n: 'modoFalla', t: 'Modo de falla ISO 14224 (al finalizar un correctivo)' }, { n: 'motivo', t: 'Motivo o nota', tipo: 'textarea' }], 'Aplicar', async (d, m) => {
          const par = [{ name: 'estado', valueCode: d.estado }];
          if (d.responsable) { const o = (await pim('GET', 'Organization?identifier=' + encodeURIComponent(d.responsable))).cuerpo.entry?.[0]?.resource; if (!o) throw new Error('No existe la organización ' + d.responsable); par.push({ name: 'responsable', valueReference: { reference: 'Organization/' + o.id } }); }
          if (d.tipoMantenimiento) par.push({ name: 'tipoMantenimiento', valueCode: d.tipoMantenimiento });
          if (d.modoFalla) par.push({ name: 'modoFalla', valueCode: d.modoFalla });
          if (d.motivo) par.push({ name: 'motivo', valueString: d.motivo });
          await pim('POST', `Task/${t.id}/$transicion`, { resourceType: 'Parameters', parameter: par }, { 'If-Match': `W/"${t.meta.versionId}"` });
          aviso(m, 'ok', 'Estado actualizado.'); setTimeout(() => { Nucleo.cerrar(); Nucleo.ir('ordenes'); }, 700);
        })}` : ''}<h3>Historial</h3><div id="oHist" class="mini">…</div>`);
    const hs = (await pim('GET', `Task/${t.id}/_history`)).cuerpo.entry || [];
    p.querySelector('#oHist').innerHTML = tabla([{ t: 'Versión', v: (x) => x.resource.meta.versionId }, { t: 'Fecha', v: (x) => fecha(x.response.lastModified) }, { t: 'Estado', v: (x) => est(x.resource) }], hs);
  }

  // Activos para elegir en la solicitud: código y nombre (el que pide no conoce los códigos de inventario, 1.1.1)
  const NS = 'https://iproy.com.ar/fhir';
  const nombreDe = (d) => d.deviceName?.[0]?.name || d.modelNumber || d.id;
  const codigoDe = (d) => (d.identifier || []).find((i) => (i.system || '').endsWith('/sid/activo'))?.value || d.identifier?.[0]?.value || '';
  async function buscarActivo(texto) {
    const t = texto.trim(); if (!t) return null;
    let a = (await pim('GET', 'Device?identifier=' + encodeURIComponent(t))).cuerpo.entry?.[0]?.resource;
    if (!a) { const l = ((await pim('GET', 'Device?device-name=' + encodeURIComponent(t) + '&_count=5')).cuerpo.entry || []).map((x) => x.resource);
      if (l.length === 1) a = l[0]; else if (l.length > 1) throw new Error('Hay varios activos con ese nombre; elegí uno de la lista: ' + l.map((d) => `${codigoDe(d)} (${nombreDe(d)})`).join(' · ')); }
    return a || null;
  }
  async function listaActivos(id) {
    const l = ((await pim('GET', 'Device?_count=200')).cuerpo.entry || []).map((x) => x.resource);
    return `<datalist id="${id}">${l.map((d) => `<option value="${h(codigoDe(d))}">${h(nombreDe(d))}${d.location?.display ? ' · ' + h(d.location.display) : ''}</option>`).join('')}</datalist>`;
  }
  Nucleo.pestana({ id: 'solicitud', titulo: 'Nueva solicitud', orden: 20, cap: 'pim.solicitar', render: async (el) => {
    el.innerHTML = `<div class="tarjeta"><h2>Pedir un trabajo</h2>${form([{ n: 'activo', t: 'Activo (escribí el código o parte del nombre y elegilo de la lista)', req: true }, { n: 'categoria', t: 'Categoría', tipo: 'select', op: [['FALLA', 'Falla'], ['RUIDO', 'Ruido o vibración'], ['FUGA', 'Fuga o pérdida'], ['ELEC', 'Eléctrico'], ['HVAC', 'Climatización'], ['OBRA', 'Obra o edilicio'], ['OTRO', 'Otro']] },
      { n: 'prioridad', t: 'Prioridad', tipo: 'select', op: [['', 'Que la calcule el sistema (IRU)'], ['routine', 'Rutina'], ['urgent', 'Urgente'], ['asap', 'Lo antes posible'], ['stat', 'Inmediata']] },
      { n: 'estadoDeclarado', t: 'Estado del equipo', tipo: 'select', op: [['', '—'], ['operativo', 'Operativo'], ['degradado', 'Degradado'], ['fuera-de-servicio', 'Fuera de servicio']] },
      { n: 'descripcion', t: 'Qué pasa', tipo: 'textarea', req: true }], 'Enviar solicitud', async (d, m) => {
        const a = await buscarActivo(d.activo);
        if (!a) throw new Error(`No existe el activo «${d.activo}». Buscalo en la lista desplegable del campo, o pedile a mantenimiento que lo dé de alta en Inventario.`);
        const sr = { resourceType: 'ServiceRequest', status: 'active', intent: 'order', subject: { reference: 'Device/' + a.id, display: nombreDe(a) },
          code: { coding: [{ code: d.categoria }], text: d.categoria }, note: [{ text: d.descripcion }], requester: { display: Nucleo.sesion.usuario.nombre } };
        if (d.prioridad) sr.priority = d.prioridad;
        if (d.estadoDeclarado) sr.extension = [{ url: NS + '/StructureDefinition/pim-estado-declarado', valueCoding: { system: NS + '/CodeSystem/pim-valores', code: d.estadoDeclarado } }];
        const r = await pim('POST', 'ServiceRequest', sr);
        aviso(m, 'ok', `Solicitud ${r.cuerpo.identifier?.[0]?.value || r.cuerpo.id} registrada para «${nombreDe(a)}»; se generó la orden ${r.cab.get('X-PIM-Task') || ''}.`);
      })}<p class="mini" id="sAyuda">Cargando la lista de activos…</p></div>`;
    try { const dl = await listaActivos('dlActivos'); el.insertAdjacentHTML('beforeend', dl); const inp = el.querySelector('input[name=activo]'); inp.setAttribute('list', 'dlActivos'); inp.setAttribute('autocomplete', 'off');
      const n = el.querySelector('#dlActivos').children.length; el.querySelector('#sAyuda').textContent = n ? `${n} activos en el inventario. Hacé clic en el campo o empezá a escribir para verlos.` : 'El inventario está vacío: pedile a mantenimiento que dé de alta los activos (pestaña Inventario).';
      inp.addEventListener('input', () => { const o = [...el.querySelector('#dlActivos').options].find((x) => x.value === inp.value); el.querySelector('#sAyuda').textContent = o ? '✔ ' + o.textContent : el.querySelector('#sAyuda').textContent; });
    } catch (x) { el.querySelector('#sAyuda').textContent = ''; }
  } });

  const TIPO_LOCAL = [['bu', 'Edificio'], ['wi', 'Ala'], ['lvl', 'Nivel o piso'], ['ro', 'Local o sala'], ['area', 'Área']];
  async function locales() {
    const l = ((await pim('GET', 'Location?_count=500')).cuerpo.entry || []).map((x) => x.resource);
    const por = {}; l.forEach((x) => { por[x.id] = x; });
    const ruta = (x) => { const r = [x.name]; let p = x.partOf?.reference?.split('/')[1]; let g = 0; while (p && por[p] && g++ < 8) { r.unshift(por[p].name); p = por[p].partOf?.reference?.split('/')[1]; } return r.join(' › '); };
    return l.map((x) => ({ id: x.id, nombre: x.name, tipo: x.physicalType?.coding?.[0]?.code || '', ruta: ruta(x), codigo: x.identifier?.[0]?.value || '', esEstab: (x.physicalType?.coding?.[0]?.code || '') === 'si' }))
      .sort((a, b) => a.ruta.localeCompare(b.ruta));
  }
  Nucleo.pestana({ id: 'inventario', titulo: 'Inventario', orden: 25, cap: 'pim.usar', render: async (el) => {
    const locs = await locales(); const opLoc = [['', '— sin ubicación —']].concat(locs.map((x) => [x.id, x.ruta]));
    el.innerHTML = `<div class="tarjeta"><div class="filtros"><div style="flex:1;min-width:220px"><label>Buscar por nombre</label><input id="iQ"></div><button class="b sec" id="iBus">Buscar</button></div><div id="iLista"></div>
      ${puede('pim.inventario.editar') ? `<h2 style="margin-top:16px">Nuevo activo</h2>${form([{ n: 'codigo', t: 'Código de inventario', req: true }, { n: 'nombre', t: 'Nombre', req: true },
        { n: 'ubicacion', t: 'Ubicación (local)', tipo: 'select', op: opLoc }, { n: 'fabricante', t: 'Fabricante' }, { n: 'modelo', t: 'Modelo' }, { n: 'serie', t: 'N.º de serie' }], 'Dar de alta', async (d, m) => {
          await pim('POST', 'Device', { resourceType: 'Device', status: 'active', identifier: [{ system: NS + '/sid/activo', value: d.codigo }], deviceName: [{ name: d.nombre, type: 'user-friendly-name' }],
            manufacturer: d.fabricante || undefined, modelNumber: d.modelo || undefined, serialNumber: d.serie || undefined, location: d.ubicacion ? { reference: 'Location/' + d.ubicacion } : undefined });
          aviso(m, 'ok', `Activo ${d.codigo} creado. Ya se puede pedir un trabajo sobre él desde «Nueva solicitud».`); cargar(); })}` : ''}</div>
      <div class="tarjeta" style="margin-top:12px"><h2>Ubicaciones</h2><p class="mini">El hospital se describe como un árbol (directorio IHE mCSD): establecimiento › edificio › nivel › local. Las solicitudes y las órdenes heredan la ubicación del activo.</p><div id="lLista"></div>
      ${puede('pim.inventario.editar') ? `<h3 style="margin-top:12px">Nueva ubicación</h3>${form([{ n: 'nombre', t: 'Nombre (ej.: Pabellón quirúrgico, Planta baja, Quirófano 1)', req: true }, { n: 'codigo', t: 'Código (opcional; se genera si queda vacío)' },
        { n: 'tipo', t: 'Tipo', tipo: 'select', op: TIPO_LOCAL }, { n: 'padre', t: 'Depende de', tipo: 'select', op: [['', 'El establecimiento (raíz)']].concat(locs.filter((x) => !x.esEstab).map((x) => [x.id, x.ruta])) }], 'Crear ubicación', async (d, m) => {
          const cod = (d.codigo || ('LOC-' + d.nombre.normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').toUpperCase().slice(0, 40))).trim();
          const body = { resourceType: 'Location', status: 'active', name: d.nombre, identifier: [{ system: NS + '/sid/local', value: cod }], physicalType: { coding: [{ system: 'http://terminology.hl7.org/CodeSystem/location-physical-type', code: d.tipo }] } };
          if (d.padre) body.partOf = { reference: 'Location/' + d.padre };
          await pim('POST', 'Location', body); aviso(m, 'ok', `Ubicación «${d.nombre}» creada.`); setTimeout(() => Nucleo.ir('inventario'), 700); })}` : ''}</div>`;
    const cargar = async () => {
      const q = document.getElementById('iQ').value.trim();
      const l = ((await pim('GET', 'Device?_count=200' + (q ? '&device-name=' + encodeURIComponent(q) : ''))).cuerpo.entry || []).map((x) => x.resource);
      document.getElementById('iLista').innerHTML = l.length ? tabla([{ t: 'Código', v: codigoDe }, { t: 'Nombre', v: nombreDe }, { t: 'Ubicación', v: (d) => d.location?.display || '' }, { t: 'Fabricante', v: (d) => d.manufacturer || '' },
        { t: 'Modelo', v: (d) => d.modelNumber || '' }, { t: 'Estado', v: (d) => d.status }], l) : '<p class="mini">Todavía no hay activos. Dalos de alta abajo: el código es el que después se usa en «Nueva solicitud».</p>';
    };
    document.getElementById('iBus').onclick = cargar; await cargar();
    const tl = (c) => (TIPO_LOCAL.find((x) => x[0] === c) || [c, c === 'si' ? 'Establecimiento' : c])[1];
    document.getElementById('lLista').innerHTML = tabla([{ t: 'Ubicación', v: (x) => x.ruta }, { t: 'Tipo', v: (x) => tl(x.tipo) }, { t: 'Código', v: (x) => x.codigo }], locs);
  } });
})();
