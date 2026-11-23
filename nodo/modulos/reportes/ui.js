// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo reportes · «Auditoría» (todas las órdenes, filtros, calificación, impresión, CSV) y «Reporte de la empresa».
(() => {
  const { h, fecha, aviso, tabla, panel, api, puede, kpis, alClicKpi, csv, icono, seccion } = Nucleo;
  const COL = { solicitada: '', 'en-revision': 'ama', asignada: '', aceptada: '', lista: '', 'en-ejecucion': 'nar', 'en-espera': 'ama', finalizada: 'verde', conformada: 'verde', rechazada: 'gris', anulada: 'gris' };
  const PRI = { stat: 'rojo', asap: 'rojo', urgent: 'nar', routine: '' };
  const et = (x) => `<span class="et ${COL[x.estado] || ''}">${h(x.estado_t)}</span>`;
  const pri = (x) => x.prioridad ? `<span class="et ${PRI[x.prioridad] || ''}">${h(x.prioridad_t)}</span>` : '';
  const estr = (n) => n ? `<span class="estr" title="${n} de 5">${'★'.repeat(n)}${'☆'.repeat(5 - n)}</span>` : '<span class="estr vacia">☆☆☆☆☆</span>';
  const horas = (v) => v == null ? '—' : v >= 48 ? (v / 24).toFixed(1).replace('.', ',') + ' días' : String(v).replace('.', ',') + ' h';
  const movil = () => innerWidth < 700;
  const dia = (d) => { const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`; };
  const hoy = () => dia(new Date());
  const menos = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return dia(d); };
  const RAP = { hoy: ['Hoy', () => [hoy(), hoy()]], '7': ['7 días', () => [menos(6), hoy()]], '30': ['30 días', () => [menos(29), hoy()]], mes: ['Este mes', () => [hoy().slice(0, 8) + '01', hoy()]], anio: ['Este año', () => [hoy().slice(0, 5) + '01-01', hoy()]] };
  const rapidos = (f) => `<div class="rapidos">${Object.entries(RAP).map(([k, [t, fn]]) => { const [a, b] = fn(); return `<button type="button" data-r="${k}" class="${f.desde === a && f.hasta === b ? 'activo' : ''}">${t}</button>`; }).join('')}</div>`;
  const num = (v) => Number(v || 0).toLocaleString('es-AR', { maximumFractionDigits: 2 });
  const durac = (m) => m == null ? '—' : m < 60 ? `${m} min` : `${Math.floor(m / 60)} h ${String(m % 60).padStart(2, '0')} min`;
  const dmy = (s) => s ? `${s.slice(8, 10)}/${s.slice(5, 7)}/${s.slice(0, 4)}` : '';
  let vista = puede('reportes.auditoria') ? 'auditoria' : 'empresa';
  const fA0 = () => ({ desde: menos(29), hasta: hoy(), fecha: 'creada', org: '', estado: '', grupo: '', prioridad: '', tipo: '', q: '' });
  let fA = fA0(), fE = { org: '', desde: menos(29), hasta: hoy(), clave: '', dia: '' }, S = null;

  Nucleo.tablero(async (el) => {
    if (!puede('reportes.auditoria')) { el.remove(); return; }
    const r = (await api('GET', 'm/reportes/auditoria?' + new URLSearchParams({ desde: menos(29), hasta: hoy() }))).resumen;
    el.innerHTML = `<div class="tarjeta"><h2>Órdenes de los últimos 30 días</h2>${kpis([{ t: 'Órdenes', n: r.total, sub: 'creadas', icono: 'ordenes', color: '#1e88e5', k: 'todas' },
      { t: 'Abiertas', n: r.abiertas, sub: r.urgentes_abiertas ? `${r.urgentes_abiertas} urgentes` : 'en curso', icono: 'reloj', color: '#fb8c00', k: 'abiertas' },
      { t: 'Terminadas', n: r.terminadas, sub: r.horas_resolucion != null ? 'resolución ' + horas(r.horas_resolucion) : 'finalizadas', icono: 'ok', color: '#43a047', k: 'terminadas' },
      { t: 'Sin calificar', n: r.sin_calificar, sub: 'terminadas', icono: 'estrella', color: '#f9a825', k: 'sin-calificar' }])}</div>`;
    alClicKpi(el, (k) => { vista = 'auditoria'; fA = { ...fA0(), grupo: k === 'todas' ? '' : k }; Nucleo.ir('reportes'); });
  }, { id: 'reportes.resumen', titulo: 'Órdenes (30 días)' });

  Nucleo.pestana({ id: 'reportes', titulo: 'Reportes', orden: 60, cap: 'reportes.empresa', render: async (el) => {
    S = S || (await api('GET', 'm/reportes/situacion')).situacion;
    const orgs = (await api('GET', 'm/reportes/organizaciones')).organizaciones;
    const todo = puede('reportes.auditoria');
    el.innerHTML = `${todo ? '<div class="subp" id="rSub"></div>' : ''}<div id="rC"></div>`;
    const pintar = () => {
      if (todo) { el.querySelector('#rSub').innerHTML = [['auditoria', 'Auditoría'], ['empresa', 'Reporte de la empresa']].map(([v, t]) => `<button data-v="${v}" class="${vista === v ? 'activo' : ''}">${t}</button>`).join('');
        el.querySelectorAll('#rSub button').forEach((b) => b.onclick = () => { vista = b.dataset.v; pintar(); }); }
      (vista === 'auditoria' && todo ? verAuditoria : verEmpresa)(el.querySelector('#rC'));
    };
    const op = (o, v, vacio) => `<option value="">${vacio}</option>` + Object.entries(o).map(([k, t]) => `<option value="${h(k)}" ${k === v ? 'selected' : ''}>${h(t)}</option>`).join('');
    const opOrg = (v, vacio, sin) => (vacio ? `<option value="">${vacio}</option>` : '') + orgs.map((o) => `<option value="${h(o.id)}" ${o.id === v ? 'selected' : ''}>${h(o.nombre)} (${o.ordenes})</option>`).join('')
      + (sin && S.sin_empresa ? `<option value="-" ${v === '-' ? 'selected' : ''}>Sin empresa asignada (${S.sin_empresa})</option>` : '');
    const enlazarRapidos = (c, f) => c.querySelectorAll('.rapidos button').forEach((b) => b.onclick = () => { [f.desde, f.hasta] = RAP[b.dataset.r][1](); f.dia = ''; pintar(); });

    // ------------------------------------------------------------------ auditoría
    async function verAuditoria(c) {
      const filtros = `${rapidos(fA)}<div class="filtros"><div><label>Desde</label><input type="date" id="aD" value="${fA.desde}" max="${hoy()}"></div><div><label>Hasta</label><input type="date" id="aH" value="${fA.hasta}" max="${hoy()}"></div>
        <div style="min-width:150px"><label>Fecha de</label><select id="aF">${op({ creada: 'Creación', finalizada: 'Finalización', movida: 'Último movimiento' }, fA.fecha, '').replace('<option value=""></option>', '')}</select></div>
        <div style="min-width:170px"><label>Empresa o área</label><select id="aO">${opOrg(fA.org, 'Todas', true)}</select></div>
        <div style="min-width:150px"><label>Estado</label><select id="aE">${op(S.estados, fA.estado, 'Todos')}</select></div>
        <div style="min-width:140px"><label>Prioridad</label><select id="aP">${op(S.prioridades, fA.prioridad, 'Todas')}</select></div>
        <div style="min-width:170px"><label>Tipo</label><select id="aT">${op(S.tipos, fA.tipo, 'Todos')}</select></div>
        <div style="flex:2 1 190px"><label>Buscar</label><input type="search" id="aQ" value="${h(fA.q)}" placeholder="Número, trabajo, equipo o lugar"></div>
        <button class="b" id="aAp">Aplicar</button><button class="b sec" id="aLi">Limpiar</button></div>`;
      const nf = ['org', 'estado', 'grupo', 'prioridad', 'tipo', 'q'].filter((k) => fA[k]).length;
      c.innerHTML = `<div id="aK"><p class="mini">Cargando…</p></div><div class="tarjeta" style="margin-top:12px">${movil() ? seccion(`Filtros · ${dmy(fA.desde)} a ${dmy(fA.hasta)}${nf ? ` · ${nf} más` : ''}`, filtros, false) : filtros}
        <div class="herr"><span class="cuenta" id="aN"></span><span class="sep"></span><button class="b sec" id="aCsv">${icono('bajar')} Descargar CSV</button></div><div id="aT2"></div></div>`;
      const g = (i) => c.querySelector(i);
      enlazarRapidos(c, fA);
      g('#aAp').onclick = () => { fA = { ...fA, desde: g('#aD').value || fA.desde, hasta: g('#aH').value || fA.hasta, fecha: g('#aF').value, org: g('#aO').value, estado: g('#aE').value, prioridad: g('#aP').value, tipo: g('#aT').value, q: g('#aQ').value.trim() }; if (fA.estado) fA.grupo = ''; pintar(); };
      g('#aLi').onclick = () => { fA = fA0(); pintar(); };
      g('#aQ').onkeydown = (e) => { if (e.key === 'Enter') g('#aAp').click(); };
      const j = await api('GET', 'm/reportes/auditoria?' + new URLSearchParams(Object.fromEntries(Object.entries(fA).filter(([, v]) => v))));
      if (!g('#aK')) return;   // mientras se esperaba la respuesta se cambió de vista: no hay dónde pintar
      const R = j.resumen, gr = fA.estado ? '-' : fA.grupo;
      g('#aK').innerHTML = kpis([{ t: 'Órdenes', n: R.total, sub: `${dmy(j.desde)} a ${dmy(j.hasta)}`, icono: 'ordenes', color: '#1e88e5', k: 'todas', activo: gr === '' },
        { t: 'Abiertas', n: R.abiertas, sub: R.en_espera ? `${R.en_espera} en espera` : 'en curso', icono: 'reloj', color: '#fb8c00', k: 'abiertas', activo: gr === 'abiertas' },
        { t: 'Urgentes abiertas', n: R.urgentes_abiertas, sub: 'urgente o más', icono: 'alarmas', color: '#e53935', k: 'urgentes', activo: gr === 'urgentes' },
        { t: 'Terminadas', n: R.terminadas, sub: R.sin_conformar ? `${R.sin_conformar} sin conformar` : 'finalizadas', icono: 'ok', color: '#43a047', k: 'terminadas', activo: gr === 'terminadas' },
        { t: 'Sin calificar', n: R.sin_calificar, sub: R.estrellas != null ? `promedio ${String(R.estrellas).replace('.', ',')} ★` : 'terminadas', icono: 'estrella', color: '#f9a825', k: 'sin-calificar', activo: gr === 'sin-calificar' },
        { t: 'Rechazadas o anuladas', n: R.rechazadas, sub: 'no se hicieron', icono: 'candado', color: '#546e7a', k: 'rechazadas', activo: gr === 'rechazadas' },
        { t: 'Resolución promedio', n: horas(R.horas_resolucion), sub: R.horas_respuesta != null ? 'respuesta ' + horas(R.horas_respuesta) : 'de creada a finalizada', icono: 'hist', color: '#00897b' }]);
      alClicKpi(g('#aK'), (k) => { fA = { ...fA, estado: '', grupo: k === 'todas' ? '' : k }; pintar(); });
      g('#aN').textContent = j.total > j.ordenes.length ? `Se muestran ${j.ordenes.length} de ${j.total}: acotá el período o los filtros` : `${j.total} orden${j.total === 1 ? '' : 'es'}`;
      g('#aT2').innerHTML = j.ordenes.length ? tabla([{ t: 'Orden', v: (x) => x.numero || x.id }, { t: 'Creada', v: (x) => fecha(x.creada) }, { t: 'Trabajo', v: (x) => x.titulo || '' }, { t: 'Equipo o lugar', v: (x) => [x.activo, x.lugar].filter(Boolean).join(' · ') },
        { t: 'Empresa o área', v: (x) => x.org || '' }, { t: 'Estado', html: true, v: et }, { t: 'Prioridad', html: true, v: pri }, { t: 'Resolución', v: (x) => x.horas_resolucion != null ? horas(x.horas_resolucion) : '' }, { t: 'Calificación', html: true, v: (x) => ['finalizada', 'conformada'].includes(x.estado) ? estr(x.estrellas) : '' }], j.ordenes, (x) => detalle(x.id))
        : '<p class="mini">No hay órdenes con esos filtros.</p>';
      g('#aCsv').onclick = () => csv(`auditoria_${j.desde}_a_${j.hasta}`, colsCsv, j.ordenes);
    }
    const colsCsv = [{ t: 'Orden', v: (x) => x.numero || x.id }, { t: 'Creada', v: (x) => x.creada }, { t: 'Inicio', v: (x) => x.inicio || '' }, { t: 'Finalizada', v: (x) => x.fin || '' }, { t: 'Trabajo', v: (x) => x.titulo || '' }, { t: 'Detalle del requerimiento', v: (x) => x.detalle || '' },
      { t: 'Equipo', v: (x) => x.activo || '' }, { t: 'Lugar', v: (x) => x.lugar || '' }, { t: 'Empresa o área', v: (x) => x.org || '' }, { t: 'Estado', v: (x) => x.estado_t }, { t: 'Motivo', v: (x) => x.motivo || '' }, { t: 'Prioridad', v: (x) => x.prioridad_t }, { t: 'Tipo', v: (x) => x.tipo_t },
      { t: 'Horas hasta iniciar', v: (x) => x.horas_respuesta ?? '' }, { t: 'Horas hasta finalizar', v: (x) => x.horas_resolucion ?? '' }, { t: 'Estrellas', v: (x) => x.estrellas ?? '' }, { t: 'Nota de la calificación', v: (x) => x.calif_nota || '' }];

    async function detalle(id) {
      const x = (await api('GET', 'm/reportes/ordenes/' + encodeURIComponent(id))).orden;
      const term = ['finalizada', 'conformada'].includes(x.estado), cal = puede('reportes.calificar') && term;
      const fila = (t, v) => v ? `<dt>${t}</dt><dd>${v}</dd>` : '';
      const p = panel('Orden ' + (x.numero || x.id), `<dl>${fila('Estado', et(x) + (x.motivo ? ' · ' + h(x.motivo) : ''))}${fila('Trabajo', h(x.titulo || ''))}${fila('Detalle', h(x.detalle || '').replace(/\n/g, '<br>'))}${fila('Equipo', h(x.activo || ''))}${fila('Lugar', h(x.lugar || ''))}
        ${fila('Empresa o área', h(x.org || ''))}${fila('Prioridad', pri(x))}${fila('Tipo', h(x.tipo_t))}${fila('Creada', fecha(x.creada))}${fila('Inicio', x.inicio ? fecha(x.inicio) + ` · a las ${horas(x.horas_respuesta)}` : '')}${fila('Finalizada', x.fin ? fecha(x.fin) + ` · a las ${horas(x.horas_resolucion)}` : '')}</dl>
        <h3>Recorrido</h3>${tabla([{ t: 'Fecha', v: (m) => fecha(m.ts) }, { t: 'Pasó de', v: (m) => m.de_t || '(alta)' }, { t: 'A', v: (m) => m.a_t }], x.movimientos)}
        ${term ? `<h3>Calificación</h3>${cal ? `<div class="estrb" id="dE">${[1, 2, 3, 4, 5].map((n) => `<button type="button" data-n="${n}" class="${n <= (x.estrellas || 0) ? 'on' : ''}" aria-label="${n} estrella${n > 1 ? 's' : ''}">★</button>`).join('')}</div>
          <label style="margin-top:8px">Nota (opcional)</label><input id="dN" maxlength="300" value="${h(x.calif_nota || '')}"><div class="acciones"><button class="b" id="dG">Guardar calificación</button>${x.estrellas ? '<button class="b sec" id="dB">Quitar</button>' : ''}</div>`
          : `<p>${estr(x.estrellas)} ${h(x.calif_nota || '')}</p>`}${x.calif_por ? `<p class="mini">Calificó ${h(x.calif_por)} el ${fecha(x.calif_ts)}</p>` : ''}` : ''}
        <div class="acciones"><button class="b sec" id="dI">${icono('imprimir')} Imprimir la orden</button></div><div id="dM"></div>`);
      let n = x.estrellas || 0;
      p.querySelectorAll('#dE button').forEach((b) => b.onclick = () => { n = +b.dataset.n; p.querySelectorAll('#dE button').forEach((y) => y.classList.toggle('on', +y.dataset.n <= n)); });
      const guardar = async (d) => { try { await api('POST', `m/reportes/ordenes/${encodeURIComponent(id)}/calificar`, d); aviso(p.querySelector('#dM'), 'ok', 'Guardado.'); setTimeout(() => { Nucleo.cerrar(); pintar(); }, 500); } catch (e) { aviso(p.querySelector('#dM'), 'mal', e.message); } };
      if (p.querySelector('#dG')) p.querySelector('#dG').onclick = () => n ? guardar({ estrellas: n, nota: p.querySelector('#dN').value }) : aviso(p.querySelector('#dM'), 'mal', 'Elegí de 1 a 5 estrellas.');
      if (p.querySelector('#dB')) p.querySelector('#dB').onclick = () => guardar({ borrar: true });
      p.querySelector('#dI').onclick = () => imprimir(x);
    }
    function imprimir(x) {
      document.querySelectorAll('.soloimp').forEach((e) => e.remove());
      const s = Nucleo.sesion, f = (t, v) => `<tr><th>${t}</th><td>${v || '&nbsp;'}</td></tr>`;
      const d = document.createElement('div'); d.className = 'imprimible soloimp';
      d.innerHTML = `<h1>Orden de trabajo ${h(x.numero || x.id)}</h1><div>${h(s?.nodo?.nombre || '')} · impresa el ${fecha(new Date().toISOString())}</div>
        <table>${f('Estado', h(x.estado_t) + (x.motivo ? ' · ' + h(x.motivo) : ''))}${f('Trabajo', h(x.titulo || ''))}${f('Detalle', h(x.detalle || '').replace(/\n/g, '<br>'))}${f('Equipo', h(x.activo || ''))}${f('Lugar', h(x.lugar || ''))}${f('Empresa o área', h(x.org || ''))}
        ${f('Prioridad', h(x.prioridad_t))}${f('Tipo', h(x.tipo_t))}${f('Creada', fecha(x.creada))}${f('Inicio', x.inicio ? fecha(x.inicio) : '')}${f('Finalizada', x.fin ? fecha(x.fin) : '')}</table>
        <table><tr><th style="width:auto">Fecha</th><th style="width:auto">Pasó de</th><th style="width:auto">A</th></tr>${x.movimientos.map((m) => `<tr><td>${fecha(m.ts)}</td><td>${h(m.de_t || '(alta)')}</td><td>${h(m.a_t)}</td></tr>`).join('')}</table>
        <table>${f('Trabajo realizado', '<br><br><br>')}${f('Materiales', '<br><br>')}${f('Observaciones', '<br><br>')}</table><div class="firmas"><div>Realizó</div><div>Conformidad del sector</div><div>Supervisó</div></div>`;
      document.body.appendChild(d); print();
    }

    // ------------------------------------------------------------------ reporte de la empresa
    async function verEmpresa(c) {
      if (!fE.org && orgs.length) fE.org = orgs[0].id;
      if (!fE.org) { c.innerHTML = '<div class="tarjeta"><p class="mini">Todavía no hay órdenes asignadas a una empresa o área.</p></div>'; return; }
      const filtros = `${rapidos(fE)}<div class="filtros">${todo || orgs.length > 1 ? `<div style="flex:2 1 220px"><label>Empresa o área</label><select id="eO">${opOrg(fE.org, '', todo)}</select></div>` : ''}
        <div><label>Desde</label><input type="date" id="eD" value="${fE.desde}" max="${hoy()}"></div><div><label>Hasta</label><input type="date" id="eH" value="${fE.hasta}" max="${hoy()}"></div><button class="b" id="eAp">Aplicar</button></div>`;
      c.innerHTML = `<div class="tarjeta">${movil() ? seccion(`Filtros · ${dmy(fE.desde)} a ${dmy(fE.hasta)}`, filtros, false) : filtros}<h2 id="eTit" style="margin:4px 0 0"></h2>
        <div class="acciones" style="margin-top:8px"><button class="b sec" id="eImp">${icono('imprimir')} Imprimir el reporte</button><button class="b sec" id="eDoc">${icono('bajar')} Reporte en Word (.docx)</button><button class="b sec" id="eCert">${icono('ok')} Certificación mensual</button></div></div><div id="eK" style="margin-top:12px"></div><div id="eC"></div>`;
      const g = (i) => c.querySelector(i);
      enlazarRapidos(c, fE);
      g('#eAp').onclick = () => { fE = { org: g('#eO')?.value ?? fE.org, desde: g('#eD').value || fE.desde, hasta: g('#eH').value || fE.hasta, clave: '', dia: '' }; pintar(); };
      if (g('#eO')) g('#eO').onchange = () => g('#eAp').click();
      let j; try { j = await api('GET', 'm/reportes/empresa?' + new URLSearchParams({ org: fE.org, desde: fE.desde, hasta: fE.hasta })); } catch (e) { g('#eK').innerHTML = `<div class="aviso mal">${h(e.message)}</div>`; return; }
      if (!g('#eTit')) return;   // se cambió de vista o de empresa mientras se esperaba la respuesta
      const R = j.resumen;
      g('#eTit').textContent = `${j.empresa.nombre} · ${dmy(j.desde)} a ${dmy(j.hasta)}`;
      const pintarDetalle = () => {
        g('#eK').innerHTML = kpis([{ t: 'Recibidas', n: R.recibidas, sub: 'en el período', icono: 'solicitud', color: '#1e88e5', k: 'ingresaron', activo: fE.clave === 'ingresaron' },
          { t: 'Terminadas', n: R.terminadas, sub: 'en el período', icono: 'ok', color: '#43a047', k: 'finalizaron', activo: fE.clave === 'finalizaron' },
          { t: 'Pendientes hoy', n: R.pendientes, sub: R.urgentes ? `${R.urgentes} urgentes` : 'sin terminar', icono: 'reloj', color: '#fb8c00' },
          { t: 'En espera', n: R.en_espera, sub: 'hoy', icono: 'candado', color: '#8e24aa', k: 'en_espera', activo: fE.clave === 'en_espera' },
          { t: 'Pendientes viejas', n: R.pendientes_viejas, sub: 'más de 30 días', icono: 'alarmas', color: '#e53935' },
          { t: 'Resolución promedio', n: horas(R.horas_resolucion), sub: R.horas_respuesta != null ? 'respuesta ' + horas(R.horas_respuesta) : 'de creada a finalizada', icono: 'hist', color: '#00897b' },
          { t: 'Calificación', n: R.estrellas != null ? String(R.estrellas).replace('.', ',') + ' ★' : '—', sub: `${R.calificadas} calificadas`, icono: 'estrella', color: '#f9a825' }]);
        alClicKpi(g('#eK'), (k) => { fE.clave = fE.clave === k ? '' : k; fE.dia = ''; pintarDetalle(); g('#eMov')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); });
        const maxC = Math.max(1, ...j.clases.map((x) => x.recibidas)), maxP = Math.max(1, ...j.pendientes.map((x) => x.n));
        const CC = { correctivo: '#e53935', preventivo: '#43a047', otros: '#1e88e5', 'sin-clasificar': '#90a4ae' };
        const movs = j.movimientos.filter((m) => (!fE.clave || m.clave === fE.clave) && (!fE.dia || m.ts.slice(0, 10) === fE.dia));
        const totC = j.clases.reduce((a, x) => a + x.recibidas, 0);
        g('#eC').innerHTML = `<div class="grid2" style="margin-top:12px"><div class="tarjeta"><h2>Preventivo y correctivo</h2>${totC ? `<div class="barras">${j.clases.filter((x) => x.recibidas).map((x) => `<div class="br" style="--bc:${CC[x.clase]}"><span>${h(x.nombre)}</span><i style="width:${Math.round(x.recibidas / maxC * 100)}%"></i><b>${x.recibidas} · ${Math.round(x.recibidas / totC * 100)} %</b></div>`).join('')}</div>
            <p class="mini" style="margin:8px 0 0">Órdenes recibidas en el período. Terminadas: ${j.clases.filter((x) => x.recibidas).map((x) => `${h(x.nombre.toLowerCase())} ${x.terminadas} de ${x.recibidas}`).join(' · ')}.</p>` : '<p class="mini">Sin órdenes recibidas en el período.</p>'}</div>
          <div class="tarjeta"><h2>Pendientes hoy, por estado</h2>${j.pendientes.length ? `<div class="barras">${j.pendientes.map((x) => `<div class="br" style="--bc:#fb8c00"><span>${h(x.estado_t)}</span><i style="width:${Math.round(x.n / maxP * 100)}%"></i><b>${x.n}</b></div>`).join('')}</div>` : '<p class="mini">✔ No tiene órdenes pendientes.</p>'}</div></div>
          <div class="tarjeta"><h2>Movimientos diarios</h2>${j.dias.length ? tabla([{ t: 'Día', v: (d) => dmy(d.dia) }, { t: 'Ingresaron', v: (d) => d.ingresaron || '' }, { t: 'Iniciaron', v: (d) => d.iniciaron || '' }, { t: 'Finalizaron', v: (d) => d.finalizaron || '' },
            { t: 'A espera', v: (d) => d.en_espera || '' }, { t: 'Otros cambios', v: (d) => d.otros || '' }, { t: 'Total', v: (d) => d.total }], j.dias, (d) => { fE.dia = fE.dia === d.dia ? '' : d.dia; fE.clave = ''; pintarDetalle(); g('#eMov')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); }) : '<p class="mini">Sin movimientos en el período.</p>'}</div>
          <div class="tarjeta"><h2>Insumos de pañol entregados a sus órdenes</h2>${j.insumos.length ? tabla([{ t: 'Insumo', v: (x) => x.codigo }, { t: 'Descripción', v: (x) => x.descripcion || '' }, { t: 'Cantidad', v: (x) => num(x.cantidad) + ' ' + (x.unidad || '') },
              { t: 'Órdenes', v: (x) => x.ordenes }, { t: 'Costo estimado', v: (x) => x.costo != null ? '$ ' + num(x.costo) : '—' }], j.insumos.slice(0, 30)) + `<p class="mini">Ranking del período por cantidad: sirve para prever compras. Costo según el último precio de ingreso del insumo.</p><div class="acciones"><button class="b sec" id="eInsX">${icono('bajar')} CSV de insumos</button></div>` : '<p class="mini">Sin egresos de pañol ligados a órdenes de esta empresa en el período.</p>'}</div>
          <div class="tarjeta" id="eMov"><h2>Detalle de movimientos${fE.dia ? ' · ' + dmy(fE.dia) : ''}${fE.clave ? ' · ' + { ingresaron: 'ingresos', finalizaron: 'finalizaciones', en_espera: 'pases a espera' }[fE.clave] : ''}</h2>
            <div class="herr"><span class="cuenta">${movs.length} movimiento${movs.length === 1 ? '' : 's'}${fE.dia || fE.clave ? ` de ${j.movimientos.length} del período` : ''}</span>${fE.dia || fE.clave ? '<button class="b sec" id="eVer">Ver todo el período</button>' : ''}<span class="sep"></span>
            <button class="b sec" id="eCsv">${icono('bajar')} CSV del período</button></div>
            ${movs.length ? tabla([{ t: 'Fecha', v: (m) => fecha(m.ts) }, { t: 'Movimiento', v: (m) => m.movimiento }, { t: 'Orden', v: (m) => m.numero || m.id }, { t: 'Trabajo', v: (m) => m.titulo || '' }, { t: 'Equipo o lugar', v: (m) => [m.activo, m.lugar].filter(Boolean).join(' · ') },
              { t: 'Estado actual', html: true, v: et }], movs.slice(0, 500), (m) => detalle(m.id)) + (movs.length > 500 ? '<p class="mini">Se muestran los 500 más nuevos; el CSV los trae todos.</p>' : '') : '<p class="mini">Sin movimientos para mostrar.</p>'}</div>`;
        if (g('#eVer')) g('#eVer').onclick = () => { fE.dia = ''; fE.clave = ''; pintarDetalle(); };
        if (g('#eInsX')) g('#eInsX').onclick = () => csv(`insumos_${(j.empresa.codigo || j.empresa.nombre)}_${j.desde}_a_${j.hasta}`, [{ t: 'Código', v: (x) => x.codigo }, { t: 'Descripción', v: (x) => x.descripcion || '' }, { t: 'Unidad', v: (x) => x.unidad || '' }, { t: 'Cantidad', v: (x) => x.cantidad }, { t: 'Órdenes', v: (x) => x.ordenes }, { t: 'Costo estimado', v: (x) => x.costo ?? '' }], j.insumos);
        // el CSV trae siempre el período completo, con el detalle del requerimiento
        g('#eCsv').onclick = () => csv(`movimientos_${(j.empresa.codigo || j.empresa.nombre)}_${j.desde}_a_${j.hasta}`, [{ t: 'Fecha', v: (m) => m.ts }, { t: 'Movimiento', v: (m) => m.movimiento }, { t: 'Orden', v: (m) => m.numero || m.id }, { t: 'Trabajo', v: (m) => m.titulo || '' },
          { t: 'Detalle del requerimiento', v: (m) => m.detalle || '' }, { t: 'Equipo', v: (m) => m.activo || '' }, { t: 'Lugar', v: (m) => m.lugar || '' }, { t: 'Prioridad', v: (m) => m.prioridad_t }, { t: 'Tipo', v: (m) => m.tipo_t }, { t: 'Orden creada', v: (m) => m.creada }, { t: 'Estado actual', v: (m) => m.estado_t }], j.movimientos);
      };
      pintarDetalle();
      g('#eImp').onclick = () => imprimirDoc(documento(j, null, false));
      g('#eDoc').onclick = async (e) => { e.target.disabled = true; try { await bajarDocx(modelo(j, null, false), `reporte_${(j.empresa.codigo || j.empresa.nombre).replace(/[^\w-]+/g, '_')}_${j.desde}_${j.hasta}`); } catch (x) { aviso(g('#eC'), 'mal', x.message); } e.target.disabled = false; };
      g('#eCert').onclick = () => certificacion(j);
    }
    // documento del reporte (para imprimir o para Word): resumen, preventivo/correctivo, pendientes, insumos y, si hay, operarios
    function documento(j, ops, cert) {
      const R = j.resumen, f = (a, v) => `<tr><th>${a}</th><td>${v ?? '—'}</td></tr>`, t = (cab, filas) => `<table><tr>${cab.map((x) => `<th style="width:auto">${x}</th>`).join('')}</tr>${filas.map((r) => `<tr>${r.map((x) => `<td>${x ?? ''}</td>`).join('')}</tr>`).join('')}</table>`;
      const nodo = Nucleo.sesion?.nodo?.nombre || '';
      return `<h1>${cert ? 'Certificación de servicios' : 'Reporte de la empresa'} · ${h(j.empresa.nombre)}</h1><div>${h(nodo)} · período ${dmy(j.desde)} a ${dmy(j.hasta)} · emitido el ${fecha(new Date().toISOString())}</div>
        <h2>Resumen</h2><table>${f('Órdenes recibidas', R.recibidas)}${f('Órdenes terminadas', R.terminadas)}${f('Pendientes a la fecha', R.pendientes + (R.urgentes ? ` (${R.urgentes} urgentes)` : ''))}${f('En espera', R.en_espera)}${f('Pendientes de más de 30 días', R.pendientes_viejas)}
          ${f('Resolución promedio', horas(R.horas_resolucion))}${f('Respuesta promedio', horas(R.horas_respuesta))}${f('Calificación promedio', R.estrellas != null ? String(R.estrellas).replace('.', ',') + ' de 5 (' + R.calificadas + ' calificadas)' : 'sin calificaciones')}</table>
        <h2>Preventivo y correctivo</h2>${t(['Clase', 'Recibidas', 'Terminadas'], j.clases.filter((x) => x.recibidas).map((x) => [h(x.nombre), x.recibidas, x.terminadas]))}
        <h2>Pendientes por estado</h2>${j.pendientes.length ? t(['Estado', 'Órdenes'], j.pendientes.map((x) => [h(x.estado_t), x.n])) : '<p>Sin pendientes.</p>'}
        <h2>Insumos de pañol entregados</h2>${j.insumos.length ? t(['Insumo', 'Descripción', 'Cantidad', 'Órdenes'], j.insumos.slice(0, 40).map((x) => [h(x.codigo), h(x.descripcion || ''), num(x.cantidad) + ' ' + h(x.unidad || ''), x.ordenes])) : '<p>Sin egresos ligados a órdenes.</p>'}
        ${ops ? `<h2>Operarios</h2>${ops.por_operario.length ? t(['Operario', 'Órdenes', 'Tiempo total', 'Promedio por orden'], ops.por_operario.map((o) => [h(o.operario), o.ordenes, durac(o.minutos), durac(o.promedio)])) : '<p>Sin trabajos registrados.</p>'}` : ''}
        ${cert ? `<h2>Conformidad</h2><table>${f('Observaciones', '<br><br><br>')}</table><div class="firmas"><div>Por la empresa</div><div>Auditoría de mantenimiento</div><div>Responsable técnico</div></div>` : ''}`;
    }
    // Ola P (P4): el mismo contenido como modelo para el .docx nativo (texto plano, sin HTML)
    function modelo(j, ops, cert) {
      const R = j.resumen; const nodo = Nucleo.sesion?.nodo?.nombre || ''; const T = (cab, filas) => ({ tipo: 'tabla', cab, filas });
      const b = [{ tipo: 'h2', texto: 'Resumen' }, T(null, [['Órdenes recibidas', R.recibidas], ['Órdenes terminadas', R.terminadas], ['Pendientes a la fecha', R.pendientes + (R.urgentes ? ` (${R.urgentes} urgentes)` : '')], ['En espera', R.en_espera],
          ['Pendientes de más de 30 días', R.pendientes_viejas], ['Resolución promedio', horas(R.horas_resolucion)], ['Respuesta promedio', horas(R.horas_respuesta)],
          ['Calificación promedio', R.estrellas != null ? String(R.estrellas).replace('.', ',') + ' de 5 (' + R.calificadas + ' calificadas)' : 'sin calificaciones']].map((f) => f.map((x) => (x ?? '—') === '' ? '—' : String(x ?? '—')))),
        { tipo: 'h2', texto: 'Preventivo y correctivo' }, j.clases.some((x) => x.recibidas) ? T(['Clase', 'Recibidas', 'Terminadas'], j.clases.filter((x) => x.recibidas).map((x) => [x.nombre, x.recibidas, x.terminadas])) : { tipo: 'p', texto: 'Sin órdenes recibidas en el período.' },
        { tipo: 'h2', texto: 'Pendientes por estado' }, j.pendientes.length ? T(['Estado', 'Órdenes'], j.pendientes.map((x) => [x.estado_t, x.n])) : { tipo: 'p', texto: 'Sin pendientes.' },
        { tipo: 'h2', texto: 'Insumos de pañol entregados' }, j.insumos.length ? T(['Insumo', 'Descripción', 'Cantidad', 'Órdenes'], j.insumos.slice(0, 40).map((x) => [x.codigo, x.descripcion || '', num(x.cantidad) + ' ' + (x.unidad || ''), x.ordenes])) : { tipo: 'p', texto: 'Sin egresos ligados a órdenes.' }];
      if (ops) b.push({ tipo: 'h2', texto: 'Operarios' }, ops.por_operario.length ? T(['Operario', 'Órdenes', 'Tiempo total', 'Promedio por orden'], ops.por_operario.map((o) => [o.operario, o.ordenes, durac(o.minutos), durac(o.promedio)])) : { tipo: 'p', texto: 'Sin trabajos registrados.' });
      if (cert) b.push({ tipo: 'h2', texto: 'Conformidad' }, T(null, [['Observaciones', '\n\n\n']]), { tipo: 'firmas', items: ['Por la empresa', 'Auditoría de mantenimiento', 'Responsable técnico'] });
      return { titulo: `${cert ? 'Certificación de servicios' : 'Reporte de la empresa'} · ${j.empresa.nombre}`, subtitulo: `${nodo} · período ${dmy(j.desde)} a ${dmy(j.hasta)} · emitido el ${fecha(new Date().toISOString())}`, bloques: b };
    }
    async function bajarDocx(mod, archivo) {
      const r = await api('POST', 'm/reportes/docx', { modelo: mod, archivo }); const bin = Uint8Array.from(atob(r.base64), (c) => c.charCodeAt(0));
      const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([bin], { type: r.tipo })); a.download = r.nombre; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    }
    function imprimirDoc(html) { document.querySelectorAll('.soloimp').forEach((e) => e.remove()); const d = document.createElement('div'); d.className = 'imprimible soloimp'; d.innerHTML = html; document.body.appendChild(d); window.print(); }
    async function certificacion(j) {
      const m0 = j.hasta.slice(0, 7);
      const p = panel('Certificación mensual · ' + j.empresa.nombre, `<p class="mini">Resumen del mes para certificar los servicios de la empresa: órdenes, preventivo y correctivo, pendientes, insumos y operarios, con espacio para las firmas.</p>
        <label>Mes</label><input type="month" id="cM" value="${m0}" max="${hoy().slice(0, 7)}"><div class="acciones"><button class="b" id="cV">Armar la certificación</button></div><div id="cR"></div>`);
      p.querySelector('#cV').onclick = async () => { const r = p.querySelector('#cR'); const m = p.querySelector('#cM').value || m0; const [y, mm] = m.split('-').map(Number);
        const desde = `${m}-01`, hasta = dia(new Date(y, mm, 0)) > hoy() ? hoy() : dia(new Date(y, mm, 0)); r.innerHTML = '<p class="mini">Armando…</p>';
        try { const jm = await api('GET', 'm/reportes/empresa?' + new URLSearchParams({ org: j.empresa.id, desde, hasta }));
          const ops = j.empresa.codigo ? await api('GET', 'm/requerimientos/operarios/reporte?' + new URLSearchParams({ org: j.empresa.codigo, desde, hasta })).catch(() => null) : null;
          const doc = documento(jm, ops, true);
          r.innerHTML = `<div class="tarjeta" style="max-height:340px;overflow:auto;font-size:13px">${doc}</div><div class="acciones"><button class="b" id="cI">${icono('imprimir')} Imprimir o guardar en PDF</button><button class="b sec" id="cW">${icono('bajar')} Descargar en Word (.docx)</button></div>`;
          r.querySelector('#cI').onclick = () => imprimirDoc(doc);
          r.querySelector('#cW').onclick = async (e) => { e.target.disabled = true; try { await bajarDocx(modelo(jm, ops, true), `certificacion_${(jm.empresa.codigo || jm.empresa.nombre).replace(/[^\w-]+/g, '_')}_${m}`); } catch (x) { aviso(r, 'mal', x.message); } e.target.disabled = false; };
        } catch (x) { aviso(r, 'mal', x.message); } };
    }
    pintar();
  } });
})();

// ================================================================== 1.2 · Tendencias y Pronóstico · Reporte General (grupo Administración, como en el sistema anterior)
(() => {
  const { h, api, kpis, csv, icono, grafico, tabla, fecha, listas, aviso } = Nucleo;
  const n1 = (v, d = 1) => (v == null ? '—' : Number(v).toLocaleString('es-AR', { maximumFractionDigits: d }));
  const mesTxt = (m) => { const [a, b] = String(m).split('-'); return ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][+b - 1] + ' ' + a.slice(2); };
  const dia = (d) => { const p = (n) => String(n).padStart(2, '0'); return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`; };
  const hoy = () => dia(new Date()); const menos = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return dia(d); };
  const dmy = (s) => (s ? `${s.slice(8, 10)}/${s.slice(5, 7)}/${s.slice(0, 4)}` : '');
  const plata = (v) => '$ ' + Number(v || 0).toLocaleString('es-AR', { maximumFractionDigits: 0 });
  const imprimirDoc = (html) => { document.querySelectorAll('.soloimp').forEach((e) => e.remove()); const d = document.createElement('div'); d.className = 'imprimible soloimp'; d.innerHTML = html; document.body.appendChild(d); window.print(); };
  const RAP = (contrato) => [['15d', '15 días', () => [menos(14), hoy()]], ['3m', '3 meses', () => [menos(91), hoy()]], ['12m', '12 meses', () => [menos(364), hoy()]],
    ...(contrato ? [['contrato', 'Desde cambio de contrato', () => [contrato, hoy()]]] : []), ['todo', 'Todo', () => [menos(1825), hoy()]]];
  async function barraFiltros(el, f, cfg) {
    const tar = await api('GET', 'm/reportes/tareas').then((r) => r.tareas).catch(() => []);
    const [orgs, srv] = await Promise.all([api('GET', 'm/reportes/organizaciones').then((r) => r.organizaciones).catch(() => []), api('GET', 'm/reportes/servicios').then((r) => r.servicios).catch(() => [])]);
    return `<div class="tarjeta"><div class="filtros"><div><label>Desde</label><input type="date" id="tfD" value="${f.desde}"></div><div><label>Hasta</label><input type="date" id="tfH" value="${f.hasta}"></div>
      <div style="min-width:170px"><label>Servicio</label><select id="tfS"><option value="">Todos</option>${srv.map((s) => `<option ${s === f.servicio ? 'selected' : ''}>${h(s)}</option>`).join('')}</select></div>
      ${orgs.length > 1 ? `<div style="min-width:170px"><label>Empresa o área</label><select id="tfE"><option value="">Todas</option>${orgs.map((o) => `<option value="${h(o.id)}" ${o.id === f.org ? 'selected' : ''}>${h(o.nombre)}</option>`).join('')}</select></div>` : ''}
      ${tar.length ? `<div style="min-width:170px"><label>Tipo de tarea (PIM)</label><select id="tfT"><option value="">Todos</option>${tar.map((t) => `<option value="${h(t.codigo)}" ${t.codigo === f.tarea ? 'selected' : ''}>${h(t.nombre)}</option>`).join('')}</select></div>` : ''}
      <div><label>Rápidos</label><div class="rapidos">${RAP(cfg.contrato).map(([k, t]) => `<button type="button" data-r="${k}">${t}</button>`).join('')}</div></div></div></div>`;
  }
  function enlazarFiltros(el, f, re) {
    const v = (id) => el.querySelector(id)?.value ?? '';
    el.querySelectorAll('#tfD,#tfH,#tfS,#tfE,#tfT').forEach((x) => x.onchange = () => { Object.assign(f, { desde: v('#tfD'), hasta: v('#tfH'), servicio: v('#tfS'), org: v('#tfE'), tarea: v('#tfT') }); re(); });
    el.querySelectorAll('.rapidos button').forEach((b) => b.onclick = () => { const r = RAP(f._contrato).find((x) => x[0] === b.dataset.r); [f.desde, f.hasta] = r[2](); re(); });
  }

  // ---------------------------------------------------------------- Tendencias y Pronóstico
  const FT = { desde: menos(364), hasta: hoy(), servicio: '', org: '', vista: 'panorama' };
  const VISTAS_T = [['panorama', 'Panorama'], ['empresas', 'Comparar empresas'], ['series', 'Series y pronóstico'], ['preventivo', 'Preventivo / Predictivo'], ['insumos', 'Insumos y compras']];
  Nucleo.pestana({ id: 'rpt_tendencias', titulo: 'Tendencias y Pronóstico', orden: 61, cap: 'reportes.auditoria', grupo: 'adm', icono: 'reportes', desc: 'Backlog, tiempos, comparación de empresas y pronóstico', render: async function pintar(el) {
    el.innerHTML = '<div class="sab kfit"><p class="mini">Calculando…</p></div>';
    const q = new URLSearchParams(Object.fromEntries(Object.entries(FT).filter(([k, v]) => v && k !== 'vista' && k[0] !== '_')));
    const T = await api('GET', 'm/reportes/tendencias?' + q); FT._contrato = T.contrato; const K = T.kpis;
    el.innerHTML = `<div class="sab kfit">${await barraFiltros(el, FT, T)}
      ${kpis([{ t: 'Ingresadas', n: n1(K.ingresadas, 0), sub: `${dmy(T.desde)} – ${dmy(T.hasta)}`, icono: 'ordenes', color: '#1e88e5' }, { t: 'Cerradas', n: n1(K.cerradas, 0), sub: 'finalizadas o conformadas', icono: 'ok', color: '#43a047' },
        { t: 'Backlog al corte', n: n1(K.backlog, 0), sub: `abiertas al ${dmy(T.hasta)}`, icono: 'reloj', color: '#fb8c00' }, { t: 'Resolución mediana', n: K.mediana_dias == null ? '—' : n1(K.mediana_dias) + ' d', sub: `p90 ${K.p90_dias == null ? '—' : n1(K.p90_dias) + ' d'}`, icono: 'hist', color: '#8e24aa' },
        { t: 'Correctivo', n: K.correctivo_pct == null ? '—' : n1(K.correctivo_pct) + ' %', sub: 'de lo clasificado', icono: 'alarmas', color: '#e53935' }, { t: 'Denegadas', n: n1(K.denegadas, 0), sub: 'rechazadas', icono: 'candado', color: '#546e7a' },
        { t: 'Backlog con pedido de insumos', n: n1(K.backlog_insumos, 0), sub: 'en espera por insumos', icono: 'stock', color: '#6d4c41' }])}
      <div class="pest">${VISTAS_T.map(([k, t]) => `<button data-v="${k}" class="${k === FT.vista ? 'activo' : ''}">${t}</button>`).join('')}</div><div id="tvC"></div>
      <div class="acciones"><button class="b sec" id="tvCsv">${icono('bajar')} CSV</button><button class="b sec" id="tvImp">${icono('imprimir')} Imprimir</button></div></div>`;
    enlazarFiltros(el, FT, () => pintar(el));
    el.querySelectorAll('.pest button').forEach((b) => b.onclick = () => { FT.vista = b.dataset.v; pintar(el); });
    const c = el.querySelector('#tvC'); const M = T.series.map((s) => mesTxt(s.mes)); let csvDef = null;
    if (FT.vista === 'panorama') {
      c.innerHTML = `<div class="grid2 ggraf"><div class="tarjeta"><h2>Ingresadas, cerradas y backlog por mes</h2>${grafico({ tipo: 'columnas', etiquetas: M, series: [{ n: 'Ingresadas', valores: T.series.map((s) => s.ingresadas) }, { n: 'Cerradas', valores: T.series.map((s) => s.cerradas), color: '#43a047' }] })}
          ${grafico({ tipo: 'lineas', etiquetas: M, leyenda: true, alto: 170, series: [{ n: 'Backlog a fin de mes', valores: T.series.map((s) => s.backlog), color: '#fb8c00' }] })}</div>
        <div class="tarjeta"><h2>Servicios con más pendientes</h2>${tabla([{ t: 'Servicio', html: true, v: (s) => (s.critica ? '<span class="et rojo" title="Área crítica">crítica</span> ' : '') + h(s.servicio) }, { t: 'Ingresadas', v: (s) => s.ingresadas }, { t: 'Pendientes', v: (s) => s.pendientes }], T.servicios)}</div>
        <div class="tarjeta"><h2>Clase de trabajo</h2>${grafico({ tipo: 'torta', etiquetas: ['Correctivo', 'Preventivo', 'Otros', 'Sin clasificar'], series: [{ n: 'Órdenes', valores: [T.clases.correctivo, T.clases.preventivo, T.clases.otros, T.clases['sin-clasificar']] }] })}</div></div>`;
      csvDef = ['tendencias_mensual', [{ t: 'Mes', v: (s) => s.mes }, { t: 'Ingresadas', v: (s) => s.ingresadas }, { t: 'Cerradas', v: (s) => s.cerradas }, { t: 'Backlog fin de mes', v: (s) => s.backlog }, { t: 'Preventivo', v: (s) => s.preventivo }, { t: 'Correctivo', v: (s) => s.correctivo }], T.series];
    } else if (FT.vista === 'empresas') {
      const E = T.empresas;
      c.innerHTML = `<div class="tarjeta"><h2>Comparar empresas</h2>${E.length ? grafico({ tipo: 'barras', etiquetas: E.slice(0, 12).map((e) => e.nombre), series: [{ n: 'Ingresadas', valores: E.slice(0, 12).map((e) => e.ingresadas) }] })
        + tabla([{ t: 'Empresa o área', v: (e) => e.nombre }, { t: 'Ingresadas', v: (e) => e.ingresadas }, { t: 'Terminadas', v: (e) => e.terminadas }, { t: 'Pendientes', v: (e) => e.pendientes },
          { t: 'Días promedio', v: (e) => n1(e.dias_promedio) }, { t: 'Correctivo', v: (e) => (e.correctivo_pct == null ? '—' : n1(e.correctivo_pct) + ' %') }, { t: 'Denegadas', v: (e) => e.denegadas }], E) : '<p class="mini">Tu usuario ve una sola empresa.</p>'}</div>
        ${E.length ? await tarjetaTraspaso(T) : ''}`;
      enlazarTraspaso(c, () => pintar(el));
      csvDef = ['tendencias_empresas', [{ t: 'Empresa', v: (e) => e.nombre }, { t: 'Ingresadas', v: (e) => e.ingresadas }, { t: 'Terminadas', v: (e) => e.terminadas }, { t: 'Pendientes', v: (e) => e.pendientes }, { t: 'Días promedio', v: (e) => e.dias_promedio ?? '' }, { t: 'Correctivo %', v: (e) => e.correctivo_pct ?? '' }, { t: 'Denegadas', v: (e) => e.denegadas }], E];
    } else if (FT.vista === 'series') {
      const P = T.pronostico, Mo = P.modelo, tf = P.timesfm, sem = P.freq === 'W'; const per = sem ? (x) => dmy(x).slice(0, 5) : mesTxt;
      const et = [...P.meses_base, ...P.meses].map(per); const nb = P.base.length;
      c.innerHTML = `<div class="tarjeta"><h2>Órdenes ingresadas por ${sem ? 'semana' : 'mes'} y pronóstico a ${sem ? '12 semanas' : '6 meses'}</h2>
        <div class="chips" style="margin-bottom:8px"><button class="chip ${sem ? '' : 'activo'}" data-fq="M">Mensual</button><button class="chip ${sem ? 'activo' : ''}" data-fq="W">Semanal</button></div>${Mo ? grafico({ tipo: 'lineas', etiquetas: et, leyenda: true, series: [
          { n: 'Real', valores: [...P.base, ...P.meses.map(() => 0)].slice(0, nb) },
          { n: 'Pronóstico (p50)', color: '#8e24aa', punteada: true, desde: nb - 1, valores: [...P.base, ...Mo.p50] },
          { n: 'Banda p10', color: '#ce93d8', punteada: true, desde: nb, valores: [...P.base.map(() => 0), ...Mo.p10] }, { n: 'Banda p90', color: '#ce93d8', punteada: true, desde: nb, valores: [...P.base.map(() => 0), ...Mo.p90] },
          ...(tf && tf.n ? [{ n: 'Google TimesFM (p50)', color: '#0d47a1', punteada: true, desde: nb - 1, valores: [...P.base, ...tf.p50.map((v) => v ?? 0)] }] : [])] })
          + `<p class="mini">${h(Mo.modelo)} · α ${Mo.parametros.alfa} · β ${Mo.parametros.beta} · γ ${Mo.parametros.gamma} · φ ${Mo.parametros.fi}${P.control ? ` · control con ${sem ? 'las últimas 8 semanas' : 'los últimos 3 meses'}: error medio ${n1(P.control.mae)} órdenes (WAPE ${n1(P.control.wape)} %), ${Math.round(P.control.cobertura * 100)} % dentro de la banda` : ''}.
            ${tf && tf.n ? `<span class="et azul">Google TimesFM</span> ${h(tf.modelo)} · corrida del ${fecha(tf.corrida)}${tf.desactualizado ? ` · <b style="color:#b26a00">con datos hasta ${h(String(tf.ultimo_dato).slice(0, 7))}: conviene correrlo de nuevo</b>` : ''}`
              : `Google TimesFM: <i>${P.serie_clave ? 'sin corrida guardada para esta serie' : 'no aplica con este filtro (se corre sobre el total, un servicio, un tipo de tarea, servicio × tarea o una empresa)'}</i> · se calcula con <code>herramientas/pronosticos/timesfm_iproy.py</code> (el instalador lo programa).`}</p>`
          + tabla([{ t: sem ? 'Semana del' : 'Mes', v: (r) => per(r[0]) }, { t: 'Holt-Winters', v: (r) => n1(r[1], 0) }, { t: 'HW p10–p90', v: (r) => `${n1(r[2], 0)} – ${n1(r[3], 0)}` },
              ...(tf && tf.n ? [{ t: 'TimesFM p50', html: true, v: (r) => (r[4] == null ? '—' : `<b>${n1(r[4], 0)}</b>`) }, { t: 'TimesFM p10–p90', v: (r) => (r[5] == null ? '—' : `${n1(r[5], 0)} – ${n1(r[6], 0)}`) }] : [])],
            P.meses.map((m, i) => [m, Mo.p50[i], Mo.p10[i], Mo.p90[i], tf?.p50?.[i], tf?.p10?.[i], tf?.p90?.[i]]))
        : `<p class="mini">Hacen falta al menos 3 ${sem ? 'semanas' : 'meses'} completos para pronosticar.</p>`}</div>`;
      c.querySelectorAll('[data-fq]').forEach((b) => b.onclick = () => { FT.freq = b.dataset.fq; pintar(el); });
      csvDef = ['pronostico_ordenes', [{ t: 'Mes', v: (r) => r[0] }, { t: 'Real', v: (r) => r[1] ?? '' }, { t: 'HW_p10', v: (r) => r[2] ?? '' }, { t: 'HW_p50', v: (r) => r[3] ?? '' }, { t: 'HW_p90', v: (r) => r[4] ?? '' },
          { t: 'TimesFM_p10', v: (r) => r[5] ?? '' }, { t: 'TimesFM_p50', v: (r) => r[6] ?? '' }, { t: 'TimesFM_p90', v: (r) => r[7] ?? '' }],
        [...P.meses_base.map((m, i) => [m, P.base[i]]), ...(Mo ? P.meses.map((m, i) => [m, null, Mo.p10[i], Mo.p50[i], Mo.p90[i], tf?.p10?.[i], tf?.p50?.[i], tf?.p90?.[i]]) : [])]];
    } else if (FT.vista === 'preventivo') {
      const S = T.series;
      c.innerHTML = `<div class="grid2 ggraf"><div class="tarjeta"><h2>Preventivo y correctivo por mes</h2>${grafico({ tipo: 'apiladas', etiquetas: M, series: [{ n: 'Correctivo', valores: S.map((s) => s.correctivo), color: '#e53935' }, { n: 'Preventivo', valores: S.map((s) => s.preventivo), color: '#43a047' }] })}</div>
        <div class="tarjeta"><h2>Cumplimiento del preventivo</h2>${tabla([{ t: 'Mes', v: (s) => mesTxt(s.mes) }, { t: 'Preventivos', v: (s) => s.preventivo }, { t: 'Cumplidos', v: (s) => s.prev_cumplido }, { t: '%', v: (s) => (s.preventivo ? n1(s.prev_cumplido / s.preventivo * 100, 0) + ' %' : '—') }], S)}
          <p class="mini">Preventivo incluye el sistemático, el condicional y el predictivo. La meta habitual es que el correctivo baje mientras el preventivo se cumple.</p></div></div>`;
      csvDef = ['preventivo_correctivo', [{ t: 'Mes', v: (s) => s.mes }, { t: 'Preventivo', v: (s) => s.preventivo }, { t: 'Cumplidos', v: (s) => s.prev_cumplido }, { t: 'Correctivo', v: (s) => s.correctivo }], S];
    } else {
      c.innerHTML = `<div class="grid2 ggraf"><div class="tarjeta"><h2>Costo de insumos entregados a órdenes</h2>${grafico({ tipo: 'columnas', etiquetas: T.insumos_mes.map((x) => mesTxt(x.m)), series: [{ n: 'Costo', valores: T.insumos_mes.map((x) => x.costo), color: '#6d4c41' }], fmt: plata })}</div>
        <div class="tarjeta"><h2>Insumos de mayor costo</h2>${tabla([{ t: 'Código', v: (x) => x.codigo }, { t: 'Descripción', v: (x) => x.descripcion || '' }, { t: 'Cantidad', v: (x) => `${n1(x.cantidad, 3)} ${x.unidad || ''}` }, { t: 'Costo', v: (x) => plata(x.costo) }], T.insumos_top)}</div></div>`;
      csvDef = ['insumos_ordenes', [{ t: 'Código', v: (x) => x.codigo }, { t: 'Descripción', v: (x) => x.descripcion }, { t: 'Cantidad', v: (x) => x.cantidad }, { t: 'Costo', v: (x) => x.costo }], T.insumos_top];
    }
    el.querySelector('#tvCsv').onclick = () => csvDef && csv(...csvDef);
    el.querySelector('#tvImp').onclick = () => imprimirDoc(`<h1>Tendencias y Pronóstico · ${h(VISTAS_T.find((v) => v[0] === FT.vista)[1])}</h1><div>${h(Nucleo.sesion?.nodo?.nombre || '')} · ${dmy(T.desde)} – ${dmy(T.hasta)}${FT.servicio ? ' · ' + h(FT.servicio) : ''} · impreso el ${fecha(new Date().toISOString())}</div>
      <table>${[['Ingresadas', K.ingresadas], ['Cerradas', K.cerradas], ['Backlog al corte', K.backlog], ['Resolución mediana (días)', n1(K.mediana_dias)], ['Resolución p90 (días)', n1(K.p90_dias)], ['Correctivo %', n1(K.correctivo_pct)], ['Denegadas', K.denegadas], ['Backlog con pedido de insumos', K.backlog_insumos]].map(([a, b]) => `<tr><th>${a}</th><td>${b ?? '—'}</td></tr>`).join('')}</table>${c.innerHTML}`);
  } });

  // ---------------------------------------------------------------- traspaso de contrato (en «Comparar empresas»)
  async function tarjetaTraspaso(T) {
    const X = T.traspaso || {}; const orgs = await api('GET', 'm/reportes/organizaciones').then((r) => r.organizaciones).catch(() => []);
    const op = (sel) => orgs.map((o) => `<option value="${h(o.id)}" ${o.id === sel ? 'selected' : ''}>${h(o.nombre)}</option>`).join('');
    const d = (v) => (v == null ? '—' : n1(v) + ' d');
    let h2 = `<div class="tarjeta"><h2>Traspaso de contrato</h2><div class="filtros"><div><label>Desde (corte)</label><input type="date" id="trC" value="${h(X.corte || FT.corte || '')}"></div>
      <div style="min-width:180px"><label>Empresa entrante</label><select id="trE"><option value="">— elegí —</option>${op(X.entrante || FT.entrante)}</select></div>
      <div style="min-width:180px"><label>Saliente (referencia)</label><select id="trS"><option value="">— ninguna —</option>${op(X.saliente || FT.saliente)}</select></div></div>`;
    if (!X.corte || X.falta) return h2 + `<p class="mini">${X.falta ? h(X.falta) : 'Elegí la fecha del cambio de contrato y la empresa entrante (se pueden dejar fijas en Configuración › Reportes).'}</p></div>`;
    const ent = h(X.entrante_nombre || 'la entrante'), sal = h(X.saliente_nombre || 'la saliente');
    h2 += kpis([{ t: 'Heredadas', n: n1(X.heredadas, 0), sub: `abiertas al traspaso · hoy siguen ${n1(X.heredadas_abiertas, 0)}`, icono: 'reloj', color: '#fb8c00' },
      { t: `Heredadas cerradas por ${X.entrante_nombre || 'entrante'}`, n: n1(X.heredadas_cerradas_entrante, 0), sub: `prom. ${d(X.heredadas_entrante_media)} · mediana ${d(X.heredadas_entrante_mediana)}`, icono: 'ok', color: '#1e88e5' },
      { t: 'Heredadas cerradas por otras', n: n1(X.heredadas_cerradas_otras, 0), sub: X.saliente ? `${n1(X.heredadas_cerradas_saliente, 0)} por ${X.saliente_nombre}` : '', icono: 'hist', color: '#546e7a' },
      { t: `Propias de ${X.entrante_nombre || 'la entrante'}`, n: n1(X.propias, 0), sub: `cerradas ${n1(X.propias_cerradas, 0)}${X.propias ? ` (${Math.round(X.propias_cerradas / X.propias * 100)} %)` : ''}`, icono: 'ordenes', color: '#43a047' },
      { t: 'Resolución de las propias', n: d(X.propias_media), sub: `mediana ${d(X.propias_mediana)}`, icono: 'reloj', color: '#43a047' },
      ...(X.saliente ? [{ t: `${X.saliente_nombre} (histórico)`, n: d(X.saliente_media), sub: `mediana ${d(X.saliente_mediana)} · 12 meses previos ${d(X.saliente_12m_media)}`, icono: 'hist', color: '#8e24aa' }] : [])]);
    h2 += grafico({ tipo: 'apiladas', etiquetas: X.meses.map(mesTxt), series: [{ n: 'Backlog heredado', valores: X.backlog_heredado, color: '#ffa726' }, { n: 'Backlog nuevo (desde el traspaso)', valores: X.backlog_nuevo, color: '#42a5f5' }] })
      + grafico({ tipo: 'lineas', etiquetas: X.meses.map(mesTxt), leyenda: true, alto: 170, series: [{ n: `Cerradas por ${X.entrante_nombre || 'la entrante'} por mes`, valores: X.cerradas_entrante, color: '#1e88e5' }] })
      + `<p class="mini">«Heredadas» = creadas antes del ${dmy(X.corte)} y abiertas ese día; tienen tiempos largos por definición (se miden desde su alta original), por eso se separan de las «propias» (creadas desde el traspaso y asignadas a ${ent}). La comparación con ${sal} usa sus órdenes anteriores al corte.</p></div>`;
    return h2;
  }
  function enlazarTraspaso(c, re) {
    const C = c.querySelector('#trC'); if (!C) return;
    const ok = () => { FT.corte = C.value; FT.entrante = c.querySelector('#trE').value; FT.saliente = c.querySelector('#trS').value; re(); };
    [C, c.querySelector('#trE'), c.querySelector('#trS')].forEach((x) => { x.onchange = ok; });
  }
  // Configuración › Reportes: fecha del cambio de contrato, empresas, áreas críticas
  Nucleo.configuracion({ id: 'reportes', titulo: 'Reportes', render: async (c) => {
    const [k, orgs] = await Promise.all([api('GET', 'm/reportes/contrato').then((r) => r.contrato), api('GET', 'm/reportes/organizaciones').then((r) => r.organizaciones)]);
    const op = (v) => orgs.map((o) => `<option value="${h(o.codigo || o.id)}" ${v && (v === o.codigo || v === o.id) ? 'selected' : ''}>${h(o.nombre)}</option>`).join('');
    c.innerHTML = `<div class="tarjeta"><h2>Tendencias y Pronóstico</h2><p class="mini">El cambio de contrato agrega el rápido «Desde cambio de contrato» y fija el traspaso en «Comparar empresas». Las áreas críticas se marcan en la lista de servicios con pendientes.</p>
      <div class="fgrid"><div class="fcampo"><label>Fecha del cambio de contrato</label><input type="date" id="cfF" value="${h(k.fecha_contrato || '')}"></div>
        <div class="fcampo"><label>Empresa entrante</label><select id="cfE"><option value="">— ninguna —</option>${op(k.contrato_entrante)}</select></div>
        <div class="fcampo"><label>Empresa saliente</label><select id="cfS"><option value="">— ninguna —</option>${op(k.contrato_saliente)}</select></div>
        <div class="fcampo" data-todo="1"><label>Áreas críticas (una por renglón, como se llaman los servicios)</label><textarea id="cfA" rows="5">${h((k.areas_criticas || []).join('\n'))}</textarea></div></div>
      <div class="acciones"><button class="b" id="cfG">Guardar</button></div><div id="cfM"></div></div>`;
    c.querySelector('#cfG').onclick = async () => { try { await api('PUT', 'm/reportes/contrato', { fecha_contrato: c.querySelector('#cfF').value, contrato_entrante: c.querySelector('#cfE').value, contrato_saliente: c.querySelector('#cfS').value,
        areas_criticas: c.querySelector('#cfA').value.split('\n') }); aviso(c.querySelector('#cfM'), 'ok', 'Guardado.'); } catch (e) { aviso(c.querySelector('#cfM'), 'mal', e.message); } };
  } });

  // ---------------------------------------------------------------- Reporte General
  const FG = { desde: hoy().slice(0, 5) + '01-01', hasta: hoy(), servicio: '', org: '' };
  Nucleo.pestana({ id: 'rpt_general', titulo: 'Reporte General', orden: 62, cap: 'reportes.auditoria', grupo: 'adm', icono: 'reportes', desc: 'Estados, tipos, servicios, empresas, actividad y costos', render: async function pintar(el) {
    el.innerHTML = '<div class="sab kfit"><p class="mini">Calculando…</p></div>';
    const G = await api('GET', 'm/reportes/general?' + new URLSearchParams(Object.fromEntries(Object.entries(FG).filter(([k, v]) => v && k[0] !== '_')))); const K = G.kpis;
    el.innerHTML = `<div class="sab kfit">${await barraFiltros(el, FG, {})}
      ${kpis([{ t: 'Órdenes', n: n1(K.total, 0), sub: `${dmy(G.desde)} – ${dmy(G.hasta)}`, icono: 'ordenes', color: '#1e88e5' }, { t: 'Abiertas', n: n1(K.abiertas, 0), sub: `${n1(K.en_espera, 0)} en espera`, icono: 'reloj', color: '#fb8c00' },
        { t: 'Terminadas', n: n1(K.terminadas, 0), sub: K.tasa_finalizacion == null ? '' : `${n1(K.tasa_finalizacion)} % del total`, icono: 'ok', color: '#43a047' }, { t: 'MTTR', n: K.mttr_horas == null ? '—' : (K.mttr_horas >= 48 ? n1(K.mttr_horas / 24) + ' d' : n1(K.mttr_horas) + ' h'), sub: 'tiempo medio de resolución', icono: 'hist', color: '#8e24aa' },
        { t: 'Rechazadas', n: n1(K.rechazadas, 0), sub: 'denegadas', icono: 'candado', color: '#546e7a' }])}
      <div class="grid2 ggraf">
        <div class="tarjeta"><h2>Distribución de estados</h2>${grafico({ tipo: 'torta', etiquetas: G.estados.map((x) => x.texto), series: [{ n: 'Órdenes', valores: G.estados.map((x) => x.n) }] })}</div>
        <div class="tarjeta"><h2>Tipo de mantenimiento</h2>${grafico({ tipo: 'torta', etiquetas: G.tipos.map((x) => x.texto), series: [{ n: 'Órdenes', valores: G.tipos.map((x) => x.n) }] })}</div>
        <div class="tarjeta"><h2>Servicios con más órdenes</h2>${grafico({ tipo: 'barras', etiquetas: G.servicios.map((x) => x.servicio), series: [{ n: 'Órdenes', valores: G.servicios.map((x) => x.n) }] })}</div>
        <div class="tarjeta"><h2>Órdenes por empresa</h2>${grafico({ tipo: 'barras', etiquetas: G.empresas.map((x) => x.empresa), series: [{ n: 'Órdenes', valores: G.empresas.map((x) => x.n), color: '#00897b' }] })}</div>
        <div class="tarjeta"><h2>Órdenes por día (últimos 60 días)</h2>${grafico({ tipo: 'columnas', etiquetas: G.dias.map((x) => dmy(x.dia).slice(0, 5)), series: [{ n: 'Órdenes', valores: G.dias.map((x) => x.n) }] })}</div>
        <div class="tarjeta"><h2>Tasa de finalización semanal</h2>${grafico({ tipo: 'lineas', etiquetas: G.semanas.map((x) => dmy(x.desde).slice(0, 5)), leyenda: true, fmt: (v) => n1(v, 0) + ' %', series: [{ n: '% terminadas de lo que ingresó en la semana', valores: G.semanas.map((x) => x.tasa), color: '#43a047' }] })}</div>
        <div class="tarjeta"><h2>Solicitudes acumuladas y distribución mensual</h2>${grafico({ tipo: 'columnas', etiquetas: G.meses.map((x) => mesTxt(x.mes)), series: [{ n: 'Ingresadas', valores: G.meses.map((x) => x.n) }, { n: 'Terminadas', valores: G.meses.map((x) => x.terminadas), color: '#43a047' }] })}
          ${grafico({ tipo: 'lineas', etiquetas: G.meses.map((x) => mesTxt(x.mes)), leyenda: true, alto: 160, series: [{ n: 'Acumuladas', valores: G.meses.map((x) => x.acumuladas), color: '#8e24aa' }] })}</div>
        <div class="tarjeta"><h2>MTTR por servicio</h2>${tabla([{ t: 'Servicio', v: (x) => x.servicio }, { t: 'Órdenes', v: (x) => x.n }, { t: 'MTTR', v: (x) => (x.mttr_horas == null ? '—' : x.mttr_horas >= 48 ? n1(x.mttr_horas / 24) + ' d' : n1(x.mttr_horas) + ' h') }], G.servicios)}</div>
        <div class="tarjeta"><h2>Equipos e instalaciones</h2>${grafico({ tipo: 'torta', etiquetas: G.clases.map((x) => (x.clase === 'equipo' ? 'Equipos médicos' : 'Instalaciones')), series: [{ n: 'Órdenes', valores: G.clases.map((x) => x.n) }] })}</div>
        <div class="tarjeta"><h2>Costo de insumos por categoría</h2>${grafico({ tipo: 'torta', etiquetas: G.insumos.por_categoria.map((x) => Nucleo.textoLista('stock_categoria', x.categoria) || x.categoria), series: [{ n: 'Costo', valores: G.insumos.por_categoria.map((x) => x.costo) }], fmt: plata })}</div>
        <div class="tarjeta"><h2>Órdenes con más costo de insumos</h2>${grafico({ tipo: 'barras', etiquetas: G.insumos.por_ot.map((x) => x.numero || x.orden_id), series: [{ n: 'Costo', valores: G.insumos.por_ot.map((x) => x.costo), color: '#6d4c41' }], fmt: plata })}</div>
        <div class="tarjeta"><h2>Evolución del costo de insumos</h2>${grafico({ tipo: 'lineas', etiquetas: G.insumos.por_mes.map((x) => mesTxt(x.mes)), leyenda: true, fmt: plata, series: [{ n: 'Costo mensual', valores: G.insumos.por_mes.map((x) => x.costo), color: '#6d4c41' }] })}</div>
      </div><div class="acciones"><button class="b sec" id="rgCsv">${icono('bajar')} CSV mensual</button><button class="b sec" id="rgImp">${icono('imprimir')} Imprimir</button></div></div>`;
    enlazarFiltros(el, FG, () => pintar(el));
    el.querySelector('#rgCsv').onclick = () => csv('reporte_general', [{ t: 'Mes', v: (x) => x.mes }, { t: 'Ingresadas', v: (x) => x.n }, { t: 'Terminadas', v: (x) => x.terminadas }, { t: 'Acumuladas', v: (x) => x.acumuladas }], G.meses);
    el.querySelector('#rgImp').onclick = () => imprimirDoc(`<h1>Reporte General</h1><div>${h(Nucleo.sesion?.nodo?.nombre || '')} · ${dmy(G.desde)} – ${dmy(G.hasta)} · impreso el ${fecha(new Date().toISOString())}</div>${el.querySelector('.ggraf').outerHTML}`);
  } });
})();
