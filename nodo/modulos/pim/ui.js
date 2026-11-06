// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo pim · pestañas Órdenes, Nueva solicitud e Inventario (todo por la API PIM con el rol del usuario).
// 1.6.0: se elige de listas (activos, organizaciones, tipos EN 13306, modos de falla ISO 14224, motivos, fabricantes, ubicaciones);
//        estados en castellano; formularios plegables «＋ Nuevo …»; códigos de activo y de ubicación sugeridos automáticamente.
(() => {
  const { h, fecha, aviso, tabla, panel, form, pim, puede, seccion, kpis } = Nucleo;
  const ESTADO_TXT = { solicitada: 'Solicitada', 'en-revision': 'En revisión', asignada: 'Asignada', aceptada: 'Aceptada', lista: 'Lista para ejecutar', 'en-ejecucion': 'En ejecución',
    'en-espera': 'En espera', finalizada: 'Finalizada', conformada: 'Conformada', rechazada: 'Rechazada', anulada: 'Anulada' };
  const ESTADO_COLOR = { solicitada: '', 'en-revision': 'ama', asignada: 'ama', aceptada: '', lista: '', 'en-ejecucion': 'nar', 'en-espera': 'gris', finalizada: 'verde', conformada: 'verde', rechazada: 'rojo', anulada: 'gris' };
  const etEstado = (e) => `<span class="et ${ESTADO_COLOR[e] || ''}">${h(ESTADO_TXT[e] || e)}</span>`;
  const DEV_EST = { active: 'Activo', inactive: 'Inactivo', 'entered-in-error': 'Cargado por error', unknown: 'Desconocido' };
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
      ${Object.keys(SIG).concat(['conformada', 'rechazada', 'anulada']).map((e) => `<option value="${e}">${h(ESTADO_TXT[e] || e)}</option>`).join('')}<option value="*">Todas</option></select></div></div><div id="oLista"></div></div>`;
    const cargar = async () => {
      const e = document.getElementById('oEst').value;
      const q = e === '' ? 'status=requested,received,accepted,ready,in-progress,on-hold' : e === '*' ? '' : 'business-status=' + e;
      const b = (await pim('GET', 'Task?' + q + '&_count=100&_sort=-authored-on')).cuerpo;
      const l = (b.entry || []).map((x) => x.resource);
      document.getElementById('oLista').innerHTML = tabla([{ t: 'Número', v: (t) => t.identifier?.[0]?.value || t.id }, { t: 'Estado', html: true, v: (t) => etEstado(est(t)) },
        { t: 'Prioridad', html: true, v: (t) => prio(t.priority) }, { t: 'Activo', v: (t) => t.focus?.display || '' }, { t: 'Responsable', v: (t) => t.owner?.display || '' },
        { t: 'Descripción', v: (t) => (t.description || '').slice(0, 90) }, { t: 'Alta', v: (t) => fecha(t.authoredOn) }], l, detalle);
    };
    document.getElementById('oEst').onchange = cargar; await cargar();
  } });
  async function detalle(t0) {
    const t = (await pim('GET', 'Task/' + t0.id)).cuerpo; const e = est(t);
    const p = panel('Orden ' + (t.identifier?.[0]?.value || t.id), `<dl><dt>Estado</dt><dd>${etEstado(e)}${t.statusReason?.text ? ' · ' + h(t.statusReason.text) : ''}</dd><dt>Prioridad</dt><dd>${prio(t.priority)}</dd>
      <dt>Activo</dt><dd>${h(t.focus?.display || '—')}</dd><dt>Responsable</dt><dd>${h(t.owner?.display || '—')}</dd><dt>Descripción</dt><dd style="white-space:pre-wrap">${h(t.description || '')}</dd>
      <dt>Versión</dt><dd>${h(t.meta?.versionId)} · ${fecha(t.meta?.lastUpdated)}</dd></dl>
      ${puede('pim.ordenes.operar') && SIG[e] ? `<h3>Cambiar estado</h3>${form([{ n: 'estado', t: 'Nuevo estado', tipo: 'select', op: SIG[e].map((x) => [x, ESTADO_TXT[x] || x]) },
        { n: 'responsable', t: 'Responsable', tipo: 'opciones', cargar: organizaciones, vacio: '— sin cambiar —', ayuda: 'Empresa o área que ejecuta (al asignar)' },
        { n: 'tipoMantenimiento', t: 'Tipo de mantenimiento', tipo: 'lista', lista: 'tipo_mantenimiento', vacio: '— sin cambiar —', ayuda: 'EN 13306' },
        { n: 'modoFalla', t: 'Modo de falla', tipo: 'lista', lista: 'modo_falla', vacio: '—', ayuda: 'ISO 14224 · obligatorio al finalizar un correctivo' }, { n: 'motivo', t: 'Motivo o nota', tipo: 'textarea' }], 'Aplicar', async (d, m) => {
          const par = [{ name: 'estado', valueCode: d.estado }];
          if (d.responsable) { const o = (await pim('GET', 'Organization?identifier=' + encodeURIComponent(d.responsable))).cuerpo.entry?.[0]?.resource; if (!o) throw new Error('No existe la organización ' + d.responsable); par.push({ name: 'responsable', valueReference: { reference: 'Organization/' + o.id } }); }
          if (d.tipoMantenimiento) par.push({ name: 'tipoMantenimiento', valueCode: d.tipoMantenimiento });
          if (d.modoFalla) par.push({ name: 'modoFalla', valueCode: d.modoFalla });
          if (d.motivo) par.push({ name: 'motivo', valueString: d.motivo });
          await pim('POST', `Task/${t.id}/$transicion`, { resourceType: 'Parameters', parameter: par }, { 'If-Match': `W/"${t.meta.versionId}"` });
          aviso(m, 'ok', 'Estado actualizado.'); setTimeout(() => { Nucleo.cerrar(); Nucleo.ir('ordenes'); }, 700);
        })}` : ''}<h3>Historial</h3><div id="oHist" class="mini">…</div>`);
    const hs = (await pim('GET', `Task/${t.id}/_history`)).cuerpo.entry || [];
    p.querySelector('#oHist').innerHTML = tabla([{ t: 'Versión', v: (x) => x.resource.meta.versionId }, { t: 'Fecha', v: (x) => fecha(x.response.lastModified) }, { t: 'Estado', html: true, v: (x) => etEstado(est(x.resource)) }], hs);
  }

  // Activos para elegir en la solicitud: código y nombre (el que pide no conoce los códigos de inventario, 1.1.1)
  const NS = 'https://iproy.com.ar/fhir';
  const nombreDe = (d) => d.deviceName?.[0]?.name || d.modelNumber || d.id;
  const codigoDe = (d) => (d.identifier || []).find((i) => (i.system || '').endsWith('/sid/activo'))?.value || d.identifier?.[0]?.value || '';
  async function activos(n = 500) { return ((await pim('GET', 'Device?_count=' + n)).cuerpo.entry || []).map((x) => x.resource); }
  /** Opciones de activo para elegir: «Nombre · código · ubicación» (quien pide no conoce los códigos de inventario). */
  async function opcionesActivo() {
    return (await activos()).filter((d) => d.status !== 'inactive').map((d) => [d.id, `${nombreDe(d)} · ${codigoDe(d)}${d.location?.display ? ' · ' + d.location.display : ''}`]).sort((a, b) => a[1].localeCompare(b[1]));
  }
  async function organizaciones() {
    const l = ((await pim('GET', 'Organization?_count=300')).cuerpo.entry || []).map((x) => x.resource);
    return l.map((o) => [o.identifier?.[0]?.value || o.id, `${o.name || o.id}${o.identifier?.[0]?.value ? ' · ' + o.identifier[0].value : ''}`]).sort((a, b) => a[1].localeCompare(b[1]));
  }
  Nucleo.pestana({ id: 'solicitud', titulo: 'Nueva solicitud', orden: 20, cap: 'pim.solicitar', render: async (el) => {
    el.innerHTML = `<div class="tarjeta"><h2>Pedir un trabajo</h2>${form([
      { n: 'activo', t: 'Equipo o instalación', tipo: 'opciones', cargar: opcionesActivo, req: true, vacio: '— elegí de la lista —', ayuda: 'Podés buscar por nombre, código o lugar', ancho: 'todo' },
      { n: 'categoria', t: 'Qué le pasa', tipo: 'lista', lista: 'categoria_solicitud', req: true, otro: false },
      { n: 'prioridad', t: 'Prioridad', tipo: 'select', op: [['', 'Que la calcule el sistema (IRU)'], ['routine', 'Rutina'], ['urgent', 'Urgente'], ['asap', 'Lo antes posible'], ['stat', 'Inmediata']] },
      { n: 'estadoDeclarado', t: 'Estado del equipo', tipo: 'select', op: [['', '— no sé —'], ['operativo', 'Funciona'], ['degradado', 'Funciona con problemas'], ['fuera-de-servicio', 'No funciona']] },
      { n: 'descripcion', t: 'Contá brevemente qué pasa', tipo: 'textarea', req: true, ph: 'Ej.: el equipo no enciende desde esta mañana; huele a quemado.' }], 'Enviar solicitud', async (d, m) => {
        if (!d.activo) throw new Error('Elegí el equipo o la instalación de la lista. Si no está, pedile a mantenimiento que lo dé de alta en Inventario.');
        if (!d.categoria) throw new Error('Elegí qué le pasa');
        const a = (await pim('GET', 'Device/' + d.activo)).cuerpo;
        const sr = { resourceType: 'ServiceRequest', status: 'active', intent: 'order', subject: { reference: 'Device/' + a.id, display: nombreDe(a) },
          code: { coding: [{ code: d.categoria }], text: Nucleo.textoLista('categoria_solicitud', d.categoria) }, note: [{ text: d.descripcion }], requester: { display: Nucleo.sesion.usuario.nombre } };
        if (d.prioridad) sr.priority = d.prioridad;
        if (d.estadoDeclarado) sr.extension = [{ url: NS + '/StructureDefinition/pim-estado-declarado', valueCoding: { system: NS + '/CodeSystem/pim-valores', code: d.estadoDeclarado } }];
        const r = await pim('POST', 'ServiceRequest', sr);
        aviso(m, 'ok', `Solicitud ${r.cuerpo.identifier?.[0]?.value || r.cuerpo.id} registrada para «${nombreDe(a)}»; se generó la orden ${r.cab.get('X-PIM-Task') || ''}.`);
      })}</div>`;
  } });

  const TIPO_LOCAL = [['bu', 'Edificio'], ['wi', 'Ala'], ['lvl', 'Nivel o piso'], ['ro', 'Local o sala'], ['area', 'Área']];
  async function locales() {
    const l = ((await pim('GET', 'Location?_count=500')).cuerpo.entry || []).map((x) => x.resource);
    const por = {}; l.forEach((x) => { por[x.id] = x; });
    const ruta = (x) => { const r = [x.name]; let p = x.partOf?.reference?.split('/')[1]; let g = 0; while (p && por[p] && g++ < 8) { r.unshift(por[p].name); p = por[p].partOf?.reference?.split('/')[1]; } return r.join(' › '); };
    return l.map((x) => ({ id: x.id, nombre: x.name, tipo: x.physicalType?.coding?.[0]?.code || '', ruta: ruta(x), codigo: x.identifier?.[0]?.value || '', esEstab: (x.physicalType?.coding?.[0]?.code || '') === 'si' }))
      .sort((a, b) => a.ruta.localeCompare(b.ruta));
  }
  /** Próximo código libre con un prefijo (ACT-000001, LOC-000001…): el usuario no tiene que inventarlo. */
  const proximo = (codigos, pre) => pre + String(codigos.map((c) => (String(c).match(new RegExp('^' + pre + '(\\d+)$')) || [])[1]).filter(Boolean).reduce((m, n) => Math.max(m, +n), 0) + 1).padStart(6, '0');
  Nucleo.pestana({ id: 'inventario', titulo: 'Inventario', orden: 25, cap: 'pim.usar', render: async (el) => {
    const [locs, lista] = await Promise.all([locales(), activos()]);
    const opLoc = locs.filter((x) => !x.esEstab).map((x) => [x.id, x.ruta]);
    const ed = puede('pim.inventario.editar');
    const enUso = lista.filter((d) => d.status === 'active').length;
    el.innerHTML = `${kpis([{ t: 'Activos', n: lista.length, sub: `${enUso} en servicio`, icono: 'inventario', color: '#1e88e5' },
        { t: 'Ubicaciones', n: locs.length, sub: 'en el árbol', icono: 'inicio', color: '#00897b' },
        { t: 'Sin ubicación', n: lista.filter((d) => !d.location).length, sub: 'conviene asignarla', icono: 'alarmas', color: '#ef6c00' }])}
      <div class="tarjeta" style="margin-top:12px"><div class="tcab"><h2>Activos</h2></div>
      <div class="filtros"><div style="flex:2 1 220px"><label>Buscar</label><input id="iQ" type="search" placeholder="Nombre, código, fabricante o modelo"></div>
        <div style="flex:1 1 200px"><label>Ubicación</label><select id="iLoc"><option value="">Todas</option>${opLoc.map(([v, t]) => `<option value="${h(v)}">${h(t)}</option>`).join('')}</select></div></div><div id="iLista"></div>
      ${ed ? seccion('Nuevo activo', form([{ n: 'nombre', t: 'Nombre', req: true, ph: 'Ej.: Bomba de agua N.º 2' },
        { n: 'ubicacion', t: 'Ubicación', tipo: 'select', op: [['', '— sin ubicación —']].concat(opLoc) },
        { n: 'fabricante', t: 'Fabricante o marca', tipo: 'lista', lista: 'fabricante', vacio: '— sin dato —', ayuda: 'Si no está, elegí «＋ Otro…» y se suma a la lista' },
        { n: 'modelo', t: 'Modelo' }, { n: 'serie', t: 'N.º de serie' },
        { n: 'codigo', t: 'Código de inventario', v: proximo(lista.map(codigoDe), 'ACT-'), req: true, ayuda: 'Sugerido; podés usar el de la etiqueta' }], 'Dar de alta', async (d, m) => {
          await pim('POST', 'Device', { resourceType: 'Device', status: 'active', identifier: [{ system: NS + '/sid/activo', value: d.codigo.trim() }], deviceName: [{ name: d.nombre, type: 'user-friendly-name' }],
            manufacturer: d.fabricante ? Nucleo.textoLista('fabricante', d.fabricante) : undefined, modelNumber: d.modelo || undefined, serialNumber: d.serie || undefined, location: d.ubicacion ? { reference: 'Location/' + d.ubicacion } : undefined });
          aviso(m, 'ok', `Activo ${d.codigo} creado. Ya se puede pedir un trabajo sobre él desde «Nueva solicitud».`); setTimeout(() => Nucleo.ir('inventario'), 900); })) : ''}</div>
      <div class="tarjeta"><div class="tcab"><h2>Ubicaciones</h2><span class="mini">Establecimiento › edificio › nivel › local (directorio IHE mCSD). Las solicitudes y las órdenes heredan la ubicación del activo.</span></div><div id="lLista"></div>
      ${ed ? seccion('Nueva ubicación', form([{ n: 'nombre', t: 'Nombre', req: true, ph: 'Ej.: Quirófano 1' }, { n: 'tipo', t: 'Tipo', tipo: 'lista', lista: 'tipo_local', req: true, otro: false, v: 'ro' },
        { n: 'padre', t: 'Depende de', tipo: 'select', op: [['', 'El establecimiento (raíz)']].concat(locs.filter((x) => !x.esEstab).map((x) => [x.id, x.ruta])) },
        { n: 'codigo', t: 'Código', v: proximo(locs.map((x) => x.codigo), 'LOC-'), ayuda: 'Sugerido; podés cambiarlo' }], 'Crear ubicación', async (d, m) => {
          const cod = (d.codigo || proximo(locs.map((x) => x.codigo), 'LOC-')).trim();
          const body = { resourceType: 'Location', status: 'active', name: d.nombre, identifier: [{ system: NS + '/sid/local', value: cod }], physicalType: { coding: [{ system: 'http://terminology.hl7.org/CodeSystem/location-physical-type', code: d.tipo }] } };
          if (d.padre) body.partOf = { reference: 'Location/' + d.padre };
          await pim('POST', 'Location', body); aviso(m, 'ok', `Ubicación «${d.nombre}» creada.`); setTimeout(() => Nucleo.ir('inventario'), 700); })) : ''}</div>`;
    const pintarLista = () => {
      const q = document.getElementById('iQ').value.trim().toLowerCase(); const lo = document.getElementById('iLoc').value;
      const l = lista.filter((d) => (!lo || d.location?.reference === 'Location/' + lo) && (!q || [nombreDe(d), codigoDe(d), d.manufacturer, d.modelNumber, d.location?.display].join(' ').toLowerCase().includes(q)));
      document.getElementById('iLista').innerHTML = l.length ? tabla([{ t: 'Nombre', v: nombreDe }, { t: 'Código', v: codigoDe }, { t: 'Ubicación', v: (d) => d.location?.display || '' }, { t: 'Fabricante', v: (d) => d.manufacturer || '' },
        { t: 'Modelo', v: (d) => d.modelNumber || '' }, { t: 'Estado', html: true, v: (d) => `<span class="et ${d.status === 'active' ? 'verde' : 'gris'}">${h(DEV_EST[d.status] || d.status)}</span>` }], l)
        : `<p class="mini">${lista.length ? 'Ningún activo coincide con el filtro.' : 'Todavía no hay activos: dalos de alta con «＋ Nuevo activo».'}</p>`;
    };
    let t; document.getElementById('iQ').oninput = () => { clearTimeout(t); t = setTimeout(pintarLista, 150); }; document.getElementById('iLoc').onchange = pintarLista; pintarLista();
    const tl = (c) => (TIPO_LOCAL.find((x) => x[0] === c) || [c, c === 'si' ? 'Establecimiento' : c])[1];
    document.getElementById('lLista').innerHTML = tabla([{ t: 'Ubicación', v: (x) => x.ruta }, { t: 'Tipo', v: (x) => tl(x.tipo) }, { t: 'Código', v: (x) => x.codigo }], locs);
  } });
})();
