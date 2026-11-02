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
    const [abiertas, urg, ejec] = await Promise.all([c('status=requested,received,accepted,ready,in-progress,on-hold'), c('priority=stat,asap&status=requested,received,accepted,ready,in-progress'), c('status=in-progress')]);
    el.innerHTML = `<div class="tarjeta"><h2>Órdenes de trabajo</h2><div class="kpis"><div class="kpi"><b>${abiertas ?? '—'}</b><span>abiertas</span></div>
      <div class="kpi"><b>${urg ?? '—'}</b><span>inmediatas o ASAP abiertas</span></div><div class="kpi"><b>${ejec ?? '—'}</b><span>en ejecución</span></div></div></div>`;
  });

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

  Nucleo.pestana({ id: 'solicitud', titulo: 'Nueva solicitud', orden: 20, cap: 'pim.solicitar', render: async (el) => {
    el.innerHTML = `<div class="tarjeta"><h2>Pedir un trabajo</h2>${form([{ n: 'activo', t: 'Código del activo (inventario)', req: true }, { n: 'categoria', t: 'Categoría', tipo: 'select', op: [['FALLA', 'Falla'], ['RUIDO', 'Ruido o vibración'], ['FUGA', 'Fuga'], ['ELECTRICO', 'Eléctrico'], ['EDILICIO', 'Edilicio'], ['OTRO', 'Otro']] },
      { n: 'prioridad', t: 'Prioridad', tipo: 'select', op: [['', 'Que la calcule el sistema (IRU)'], ['routine', 'Rutina'], ['urgent', 'Urgente'], ['asap', 'Lo antes posible'], ['stat', 'Inmediata']] },
      { n: 'estadoDeclarado', t: 'Estado del equipo', tipo: 'select', op: [['', '—'], ['operativo', 'Operativo'], ['degradado', 'Degradado'], ['fuera-de-servicio', 'Fuera de servicio']] },
      { n: 'descripcion', t: 'Qué pasa', tipo: 'textarea', req: true }], 'Enviar solicitud', async (d, m) => {
        const a = (await pim('GET', 'Device?identifier=' + encodeURIComponent(d.activo))).cuerpo.entry?.[0]?.resource;
        if (!a) throw new Error('No existe el activo ' + d.activo);
        const sr = { resourceType: 'ServiceRequest', status: 'active', intent: 'order', subject: { reference: 'Device/' + a.id },
          code: { coding: [{ code: d.categoria }], text: d.categoria }, note: [{ text: d.descripcion }], requester: { display: Nucleo.sesion.usuario.nombre } };
        if (d.prioridad) sr.priority = d.prioridad;
        if (d.estadoDeclarado) sr.extension = [{ url: 'https://iproy.com.ar/pim/StructureDefinition/pim-estado-declarado', valueCode: d.estadoDeclarado }];
        const r = await pim('POST', 'ServiceRequest', sr);
        aviso(m, 'ok', `Solicitud ${r.cuerpo.identifier?.[0]?.value || r.cuerpo.id} registrada; se generó la orden ${r.cab.get('X-PIM-Task') || ''}.`);
      })}</div>`;
  } });

  Nucleo.pestana({ id: 'inventario', titulo: 'Inventario', orden: 25, cap: 'pim.usar', render: async (el) => {
    el.innerHTML = `<div class="tarjeta"><div class="filtros"><div style="flex:1;min-width:220px"><label>Buscar por nombre</label><input id="iQ"></div><button class="b sec" id="iBus">Buscar</button></div><div id="iLista"></div>
      ${puede('pim.inventario.editar') ? `<h2 style="margin-top:16px">Nuevo activo</h2>${form([{ n: 'codigo', t: 'Código de inventario', req: true }, { n: 'nombre', t: 'Nombre', req: true },
        { n: 'fabricante', t: 'Fabricante' }, { n: 'modelo', t: 'Modelo' }, { n: 'serie', t: 'N.º de serie' }], 'Dar de alta', async (d, m) => {
          await pim('POST', 'Device', { resourceType: 'Device', status: 'active', identifier: [{ system: 'https://iproy.com.ar/pim/sid/activo', value: d.codigo }], deviceName: [{ name: d.nombre, type: 'user-friendly-name' }],
            manufacturer: d.fabricante || undefined, modelNumber: d.modelo || undefined, serialNumber: d.serie || undefined });
          aviso(m, 'ok', 'Activo creado.'); })}` : ''}</div>`;
    const cargar = async () => {
      const q = document.getElementById('iQ').value.trim();
      const l = ((await pim('GET', 'Device?_count=200' + (q ? '&device-name=' + encodeURIComponent(q) : ''))).cuerpo.entry || []).map((x) => x.resource);
      document.getElementById('iLista').innerHTML = tabla([{ t: 'Código', v: (d) => d.identifier?.[0]?.value }, { t: 'Nombre', v: (d) => d.deviceName?.[0]?.name }, { t: 'Fabricante', v: (d) => d.manufacturer || '' },
        { t: 'Modelo', v: (d) => d.modelNumber || '' }, { t: 'Estado', v: (d) => d.status }], l);
    };
    document.getElementById('iBus').onclick = cargar; await cargar();
  } });
})();
