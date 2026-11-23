// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo auditoria · «Auditoría Mant»: panel de todas las órdenes para quien audita.
//   Lee    → m/reportes/auditoria (copia de consulta: filtra y pagina en el servidor).
//   Decide → Task/$transicion de la API PIM, con If-Match. La prioridad respeta el IRU: se muestra la sugerida y la decisión queda registrada.
(() => {
  const { h, fecha, aviso, tabla, panel, api, pim, puede, kpis, alClicKpi, csv, icono } = Nucleo;
  const $ = (s, r = document) => r.querySelector(s);
  // estados PIM (Tabla 9-2) agrupados como los ve quien audita
  const GRUPO = { solicitada: 'Pendiente', 'en-revision': 'Revisión', asignada: 'En Proceso', aceptada: 'En Proceso', lista: 'En Proceso', 'en-ejecucion': 'En Proceso', 'en-espera': 'Atención', finalizada: 'Finalizado', conformada: 'Finalizado', rechazada: 'Denegado', anulada: 'Denegado' };
  const GRUPOS = [['Finalizado', '#2e7d32', 'ok'], ['En Proceso', '#1565c0', 'reloj'], ['Atención', '#ef6c00', 'alarmas'], ['Revisión', '#6a1b9a', 'filtro'], ['Pendiente', '#546e7a', 'ordenes'], ['Denegado', '#b71c1c', 'candado']];
  const COLOR = Object.fromEntries(GRUPOS.map(([g, c]) => [g, c]));
  const TXT = { solicitada: 'Solicitada', 'en-revision': 'En revisión', asignada: 'Asignada', aceptada: 'Aceptada', lista: 'Lista para ejecutar', 'en-ejecucion': 'En ejecución', 'en-espera': 'En espera', finalizada: 'Finalizada', conformada: 'Conformada', rechazada: 'Rechazada', anulada: 'Anulada' };
  const SIG = { solicitada: ['en-revision', 'asignada', 'rechazada', 'anulada'], 'en-revision': ['asignada', 'rechazada', 'anulada'], asignada: ['aceptada', 'en-revision', 'anulada'], aceptada: ['lista', 'en-ejecucion', 'en-espera', 'anulada'],
    lista: ['en-ejecucion', 'en-espera', 'anulada'], 'en-ejecucion': ['en-espera', 'finalizada'], 'en-espera': ['en-ejecucion', 'aceptada', 'anulada'], finalizada: ['conformada', 'en-ejecucion'] };
  const CON_MOTIVO = ['en-espera', 'rechazada', 'anulada', 'en-revision'];
  const PRI = { stat: 'Emergencia', asap: 'Muy urgente', urgent: 'Urgente', routine: 'Normal' };
  /** El activo como se busca: «código · nombre» (1.5.0 de reportes: el código vigente viaja con la orden). */
  const actTxt = (o) => [o.activo_codigo, o.activo].filter(Boolean).join(' · ');
  const numCorto = (n) => String(n || '').replace(/^.*?-0*(\d+)$/, '$1');
  const dia = (d) => d.toISOString().slice(0, 10);
  const PERIODOS = { 30: 'Últimos 30 días', 90: 'Últimos 90 días', 365: 'Último año', 1825: 'Cinco años (todo)' };
  const estr = (n) => n ? '★'.repeat(n) + '☆'.repeat(5 - n) : '';
  const etG = (e) => `<span class="et" style="background:${COLOR[GRUPO[e]] || '#546e7a'};color:#fff">${h(GRUPO[e] || e)}</span><br><small class="mini">${h(TXT[e] || e)}</small>`;

  // Un mismo panel para las dos auditorías: instalaciones («Auditoría Mant») y equipamiento médico («Auditor Bio»).
  const PANELES = [{ id: 'audit_sabanas', titulo: 'Auditoría Mant', orden: 30, icono: 'filtro', clase: 'instalacion', desc: 'Auditar y decidir sobre las órdenes de instalaciones', archivo: 'auditoria-mant', sobre: 'Instalación' },
    { id: 'bio_auditor', titulo: 'Auditor Bio', orden: 31, icono: 'inventario', clase: 'equipo', desc: 'Auditar y decidir sobre las órdenes de equipamiento médico', archivo: 'auditor-bio', sobre: 'Equipo médico' }];
  for (const PN of PANELES) Nucleo.pestana({ id: PN.id, titulo: PN.titulo, orden: PN.orden, cap: 'reportes.auditoria', grupo: 'mant', icono: PN.icono, desc: PN.desc, render: async function pintar(el) {
    const [cfg, todas, area, L] = await Promise.all([api('GET', 'm/auditoria/estado'), api('GET', 'm/reportes/organizaciones').then((r) => r.organizaciones || []).catch(() => []),
      api('GET', 'm/auditoria/areas/' + PN.clase).then((r) => r.empresas).catch(() => []), Nucleo.listas(['tipo_mantenimiento'])]);
    // área de auditoría: el panel ofrece solo sus empresas (si no hay ninguna marcada, todas)
    const orgs = area.length ? todas.filter((o) => area.includes(o.id)) : todas;
    const POR = cfg.por_pagina; const opera = puede('pim.ordenes.operar'); const tipos = (L.tipo_mantenimiento?.valores || []).filter((v) => v.activo !== 0);
    const hoy = new Date(); const F = { dias: 30, desde: dia(new Date(hoy - 29 * 864e5)), hasta: dia(hoy), grupo: '', lugar: '', org: '', q: '' };
    const pq = Nucleo.parametros.get('q'); if (pq) { F.q = pq; Nucleo.limpiarParametros(); }   // Ola P: llegó desde «Buscar la orden con el código…» del inicio
    let filas = [], total = 0, res = { por_estado: {} }, lugares = {}, cargando = false, tq = null;
    el.innerHTML = `<div class="sab"><div class="tarjeta"><div style="display:flex;flex-wrap:wrap;gap:8px;align-items:end">
        <div class="fcampo" style="flex:0 0 170px"><label>Período</label><select id="aP">${Object.entries(PERIODOS).map(([k, t]) => `<option value="${k}">${t}</option>`).join('')}<option value="0">Entre fechas…</option></select></div>
        <div class="fcampo" style="flex:0 0 150px"><label>Desde</label><input type="date" id="aD" value="${F.desde}"></div><div class="fcampo" style="flex:0 0 150px"><label>Hasta</label><input type="date" id="aH" value="${F.hasta}"></div>
        <div class="fcampo" style="flex:1 1 200px"><label>Servicio</label><select id="aL"><option value="">Todos los servicios</option></select></div>
        <div class="fcampo" style="flex:1 1 200px"><label>Empresa o área</label><select id="aO"><option value="">Todas</option><option value="-">Sin asignar</option>${orgs.map((o) => `<option value="${h(o.id)}">${h(o.nombre)}</option>`).join('')}</select></div>
        <div class="fcampo" style="flex:2 1 220px"><label>Buscar</label><input type="search" id="aQ" placeholder="🔍 Orden o código (368-APA-…), detalle, equipo o lugar" value="${h(F.q)}"></div>
        <button class="b sec" id="aX0">Limpiar</button><button class="b sec" id="aX">${icono('bajar')} Excel</button>${cfg.configura ? `<button class="b sec" id="aAr" title="Qué empresas o áreas ofrece este panel">${icono('filtro')} Empresas del panel</button>` : ''}</div></div>
      <div id="aK"></div><div id="aMsg"></div><div class="tarjeta"><div id="aC"><p class="mini">Cargando…</p></div></div></div>`;
    const consulta = (extra = {}) => { const p = new URLSearchParams({ desde: F.desde, hasta: F.hasta, clase: PN.clase, ...extra }); if (F.lugar) p.set('lugar', F.lugar); if (F.org) p.set('org', F.org); if (F.q) p.set('q', F.q);
      if (F.grupo) p.set('estados', Object.keys(GRUPO).filter((e) => GRUPO[e] === F.grupo).join(',')); return 'm/reportes/auditoria?' + p; };
    const cargar = async (mas = false) => { if (cargando) return; cargando = true;
      try { const r = await api('GET', consulta({ limite: POR, desde_fila: mas ? filas.length : 0, ...(mas ? {} : { con_lugares: 1 }) }));
        filas = mas ? filas.concat(r.ordenes) : r.ordenes; total = r.total; res = r.resumen; if (r.lugares) lugares = r.lugares; pinta(!mas); }
      catch (x) { aviso($('#aMsg', el), 'mal', x.message); } finally { cargando = false; } };
    const pinta = (conFiltros) => {
      const cta = {}; for (const [e, n] of Object.entries(res.por_estado || {})) cta[GRUPO[e]] = (cta[GRUPO[e]] || 0) + n;
      $('#aK', el).innerHTML = kpis([...GRUPOS.map(([g, color, ic]) => ({ t: g, n: cta[g] || 0, k: g, color, icono: ic, activo: F.grupo === g })), { t: 'Total', n: res.total || 0, k: '__t', color: '#37474f', icono: 'ordenes', activo: !F.grupo }]);
      alClicKpi($('#aK', el), (k) => { F.grupo = k === '__t' ? '' : k; cargar(); });
      if (conFiltros) { const s = $('#aL', el); s.innerHTML = '<option value="">Todos los servicios</option>' + Object.entries(lugares).map(([l, n]) => `<option value="${h(l)}">${h(l)} (${n})</option>`).join(''); s.value = F.lugar; if (s.value !== F.lugar) s.value = ''; }
      $('#aC', el).innerHTML = `${tabla([{ t: 'Orden', html: true, v: (o) => `<b class="nord">${h(numCorto(o.numero))}</b>` }, { t: 'Fecha', v: (o) => fecha(o.creada) }, { t: 'Servicio', v: (o) => o.lugar || '' },
          { t: 'Trabajo', html: true, v: (o) => `${h(o.titulo || '')}${o.activo ? ` · <span class="mini">${h(actTxt(o))}</span>` : ''}${o.detalle ? `<br><small>${h(o.detalle.slice(0, 140))}</small>` : ''}` },
          { t: 'Estado', html: true, v: (o) => etG(o.estado) }, { t: 'Empresa o área', v: (o) => o.org || '—' }, { t: 'Prioridad', v: (o) => o.prioridad_t || '' }, { t: 'Tipo', v: (o) => o.tipo_t || '' },
          { t: 'Valoración', html: true, v: (o) => `<span style="color:#f9a825">${estr(o.estrellas)}</span>` }, { t: '', html: true, v: (o) => `<button class="b sec aE" data-id="${h(o.id)}">${opera ? 'Editar' : 'Ver'}</button>` }], filas)}
        <p class="mini">${total ? `Mostrando ${filas.length.toLocaleString('es-AR')} de ${total.toLocaleString('es-AR')} órdenes` : 'Sin órdenes con esos filtros'}${filas.length < total ? ` · <button class="b sec" id="aMas">Cargar ${Math.min(POR, total - filas.length)} más</button>` : ''}</p>`;
      const m = $('#aMas', el); if (m) m.onclick = () => cargar(true);
      el.querySelectorAll('.aE').forEach((b) => b.onclick = () => orden(b.dataset.id));
    };
    const fechas = () => { F.desde = $('#aD', el).value || F.desde; F.hasta = $('#aH', el).value || F.hasta; $('#aP', el).value = '0'; cargar(); };
    $('#aP', el).onchange = (e) => { const d = +e.target.value; if (!d) return; F.dias = d; F.hasta = dia(new Date()); F.desde = dia(new Date(Date.now() - (d - 1) * 864e5)); $('#aD', el).value = F.desde; $('#aH', el).value = F.hasta; cargar(); };
    $('#aD', el).onchange = fechas; $('#aH', el).onchange = fechas;
    $('#aL', el).onchange = (e) => { F.lugar = e.target.value; cargar(); }; $('#aO', el).onchange = (e) => { F.org = e.target.value; cargar(); };
    $('#aQ', el).oninput = (e) => { clearTimeout(tq); tq = setTimeout(() => { F.q = e.target.value.trim(); cargar(); }, 350); };
    $('#aX0', el).onclick = () => { Object.assign(F, { grupo: '', lugar: '', org: '', q: '' }); $('#aQ', el).value = ''; $('#aO', el).value = ''; cargar(); };
    if ($('#aAr', el)) $('#aAr', el).onclick = () => { const p = panel('Empresas de «' + PN.titulo + '»', `<p class="mini">Marcá las empresas o áreas que trabajan para este panel. Una empresa puede estar en los dos paneles. Sin ninguna marcada, el panel ofrece todas.</p>
        ${todas.map((o) => `<label class="fchk"><input type="checkbox" class="arC" value="${h(o.id)}" ${area.includes(o.id) ? 'checked' : ''}> ${h(o.nombre)}</label>`).join('') || '<p class="mini">Todavía no hay empresas con órdenes.</p>'}
        <div class="acciones"><button class="b" id="arG">Guardar</button></div><div id="arM"></div>`);
      p.querySelector('#arG').onclick = async () => { try { await api('PUT', 'm/auditoria/areas/' + PN.clase, { empresas: [...p.querySelectorAll('.arC:checked')].map((x) => x.value) }); Nucleo.cerrar(); pintar(el); } catch (x) { aviso(p.querySelector('#arM'), 'mal', x.message); } }; };
    $('#aX', el).onclick = async () => { try { const r = await api('GET', consulta());   // todo lo filtrado (hasta el tope del módulo reportes), no solo lo que está en pantalla
        csv(PN.archivo, [{ t: 'Orden', v: (o) => numCorto(o.numero) }, { t: 'Número PIM', v: (o) => o.numero }, { t: 'Fecha', v: (o) => (o.creada || '').slice(0, 10) }, { t: 'Servicio', v: (o) => o.lugar || '' }, { t: 'Trabajo', v: (o) => o.titulo || '' },
          { t: PN.sobre, v: (o) => actTxt(o) }, { t: 'Detalle', v: (o) => o.detalle || '' }, { t: 'Estado', v: (o) => GRUPO[o.estado] || '' }, { t: 'Estado PIM', v: (o) => o.estado_t }, { t: 'Motivo', v: (o) => o.motivo || '' },
          { t: 'Empresa o área', v: (o) => o.org || '' }, { t: 'Prioridad', v: (o) => o.prioridad_t || '' }, { t: 'Tipo', v: (o) => o.tipo_t || '' }, { t: 'Inicio', v: (o) => (o.inicio || '').slice(0, 16) }, { t: 'Fin', v: (o) => (o.fin || '').slice(0, 16) },
          { t: 'Horas de resolución', v: (o) => o.horas_resolucion ?? '' }, { t: 'Estrellas', v: (o) => o.estrellas ?? '' }], r.ordenes);
        if (r.total > r.ordenes.length) aviso($('#aMsg', el), 'info', `El archivo trae ${r.ordenes.length.toLocaleString('es-AR')} de ${r.total.toLocaleString('es-AR')} órdenes: achicá el período para bajarlas todas.`); } catch (x) { aviso($('#aMsg', el), 'mal', x.message); } };

    // ------------------------------------------------------------ ventana de la orden
    async function orden(id) {
      let x, t; try { [x, t] = await Promise.all([api('GET', 'm/reportes/ordenes/' + encodeURIComponent(id)).then((r) => r.orden), pim('GET', 'Task/' + id).then((r) => r.cuerpo)]); } catch (e) { aviso($('#aMsg', el), 'mal', e.message); return; }
      const e0 = t.businessStatus?.coding?.[0]?.code || x.estado; const term = ['finalizada', 'conformada'].includes(e0); const cal = puede('reportes.calificar') && term;
      const ext = (n) => (t.extension || []).find((y) => (y.url || '').endsWith(n)); const dp = ext('pim-decision-prioridad'); const sub = (n) => (dp?.extension || []).find((y) => y.url === n);
      const sug = sub('sugerida')?.valueCode || ''; const dec = sub('decision')?.valueCoding?.code || ''; const tipo0 = ext('pim-tipo-mantenimiento')?.valueCoding?.code || ''; const org0 = (t.owner?.reference || '').split('/')[1] || '';
      const fila = (a, v) => v ? `<dt>${a}</dt><dd>${v}</dd>` : '';
      const p = panel('Orden ' + numCorto(x.numero), `<dl>${fila('Número PIM', h(x.numero))}${fila('Estado', etG(e0).replace('<br>', ' ') + (x.motivo ? ' · ' + h(x.motivo) : ''))}${fila('Servicio', h(x.lugar || ''))}${fila('Trabajo', h(x.titulo || ''))}${fila(PN.sobre, h(actTxt(x) || ''))}
          ${fila('Detalle', h(x.detalle || '').replace(/\n/g, '<br>'))}${fila('Creada', fecha(x.creada))}${fila('Inicio', x.inicio ? fecha(x.inicio) : '')}${fila('Finalizada', x.fin ? fecha(x.fin) : '')}</dl>
        ${opera ? `<h3>Decidir</h3><div class="fgrid">
          <div class="fcampo"><label>Pasar a</label><select id="oE"><option value="">(sin cambio: ${h(TXT[e0] || e0)})</option>${(SIG[e0] || []).map((s) => `<option value="${s}">${h(GRUPO[s])} · ${h(TXT[s])}</option>`).join('')}</select></div>
          <div class="fcampo"><label>Motivo o anotación</label><input id="oM" maxlength="255" placeholder="Obligatorio para espera, revisión, rechazo y anulación"></div>
          <div class="fcampo"><label>Empresa o área</label><select id="oO"><option value="">— Sin asignar —</option>${orgs.map((o) => `<option value="${h(o.id)}" ${o.id === org0 ? 'selected' : ''}>${h(o.nombre)}</option>`).join('')}</select></div>
          <div class="fcampo"><label>Tipo de mantenimiento</label><select id="oT"><option value="">—</option>${tipos.map((v) => `<option value="${h(v.codigo)}" ${v.codigo === tipo0 ? 'selected' : ''}>${h(v.texto)}</option>`).join('')}</select></div>
          <div class="fcampo"><label>Modo de falla (ISO 14224, 3 letras)</label><input id="oF" maxlength="3" style="text-transform:uppercase" placeholder="Para finalizar un correctivo"></div>
          <div class="fcampo"><label>Prioridad</label><select id="oP">${Object.entries(PRI).map(([k, v]) => `<option value="${k}" ${k === t.priority ? 'selected' : ''}>${v}${k === sug ? ' · sugerida por el IRU' : ''}</option>`).join('')}</select>
            <small class="fayuda">${sug ? `El IRU del activo sugiere <b>${h(PRI[sug] || sug)}</b>${sub('fundamento')?.valueString ? ` (${h(sub('fundamento').valueString)})` : ''}. Decisión registrada: ${h(dec || 'pendiente')}${sub('motivo')?.valueString ? ` · ${h(sub('motivo').valueString)}` : ''}.` : 'El activo no tiene IRU cargado: no hay prioridad sugerida.'}</small></div>
          ${sug ? '<div class="fcampo" data-todo="1" id="oMPc" style="display:none"><label>Motivo para no seguir la prioridad sugerida <b class="req">*</b></label><input id="oMP" maxlength="255"></div>' : ''}
          <div class="fcampo" data-todo="1"><label>Nota para agregar a la orden</label><input id="oN" maxlength="1000"></div></div>
          <div class="acciones"><button class="b" id="oG">Guardar cambios</button></div>` : ''}
        <h3>Recorrido</h3>${tabla([{ t: 'Fecha', v: (m) => fecha(m.ts) }, { t: 'Pasó de', v: (m) => m.de_t || '(alta)' }, { t: 'A', v: (m) => m.a_t }], x.movimientos)}
        ${term ? `<h3>Valoración del trabajo</h3>${cal ? `<div class="estrb" id="oS">${[1, 2, 3, 4, 5].map((n) => `<button type="button" data-n="${n}" class="${n <= (x.estrellas || 0) ? 'on' : ''}" aria-label="${n} estrella${n > 1 ? 's' : ''}">★</button>`).join('')}</div>
          <label style="margin-top:8px">Nota (opcional)</label><input id="oSN" maxlength="300" value="${h(x.calif_nota || '')}"><div class="acciones"><button class="b" id="oSG">Guardar valoración</button></div>` : `<p>${estr(x.estrellas)} ${h(x.calif_nota || '')}</p>`}` : ''}
        <div class="acciones"><button class="b sec" id="oI">${icono('imprimir')} Imprimir la orden</button></div><div id="oMsg"></div>`);
      const q = (s) => p.querySelector(s); const msg = q('#oMsg');
      q('#oI').onclick = () => { document.querySelectorAll('.soloimp').forEach((e) => e.remove()); const f = (a, v) => `<tr><th>${a}</th><td>${v || '&nbsp;'}</td></tr>`; const d = document.createElement('div'); d.className = 'imprimible soloimp';
        d.innerHTML = `<h1>Orden de trabajo ${h(numCorto(x.numero))}</h1><div>${h(Nucleo.sesion?.nodo?.nombre || '')} · ${h(x.numero || '')} · impresa el ${fecha(new Date().toISOString())}</div>
          <table>${f('Estado', h(GRUPO[e0] || '') + ' · ' + h(TXT[e0] || e0) + (x.motivo ? ' · ' + h(x.motivo) : ''))}${f('Servicio', h(x.lugar || ''))}${f('Trabajo', h(x.titulo || ''))}${f(PN.sobre, h(actTxt(x) || ''))}${f('Detalle', h(x.detalle || '').replace(/\n/g, '<br>'))}${f('Empresa o área', h(x.org || ''))}
          ${f('Prioridad', h(x.prioridad_t || '') + (sug ? ` (el IRU sugiere ${h(PRI[sug] || sug)}; decisión: ${h(dec || 'pendiente')})` : ''))}${f('Tipo', h(x.tipo_t || ''))}${f('Creada', fecha(x.creada))}${f('Inicio', x.inicio ? fecha(x.inicio) : '')}${f('Finalizada', x.fin ? fecha(x.fin) : '')}</table>
          <table><tr><th style="width:auto">Fecha</th><th style="width:auto">Pasó de</th><th style="width:auto">A</th></tr>${x.movimientos.map((m) => `<tr><td>${fecha(m.ts)}</td><td>${h(m.de_t || '(alta)')}</td><td>${h(m.a_t)}</td></tr>`).join('')}</table>
          <table>${f('Trabajo realizado', '<br><br><br>')}${f('Materiales', '<br><br>')}${f('Observaciones', '<br><br>')}</table><div class="firmas"><div>Realizó</div><div>Conformidad del sector</div><div>Supervisó</div></div>`;
        document.body.appendChild(d); window.print(); };
      if (opera) {
        if (sug) q('#oP').onchange = () => { q('#oMPc').style.display = q('#oP').value !== sug && q('#oP').value !== t.priority ? '' : 'none'; };
        q('#oG').onclick = async () => { const par = []; const e1 = q('#oE').value, mo = q('#oM').value.trim(), o1 = q('#oO').value, t1 = q('#oT').value, p1 = q('#oP').value, mf = q('#oF').value.trim().toUpperCase(), nt = q('#oN').value.trim(), mp = (q('#oMP')?.value || '').trim();
          if (e1) { if (CON_MOTIVO.includes(e1) && !mo) return aviso(msg, 'mal', `Pasar a «${TXT[e1]}» pide el motivo.`); par.push({ name: 'estado', valueCode: e1 }); }
          if (mo && e1) par.push({ name: 'motivo', valueString: mo });
          if (o1 && o1 !== org0) par.push({ name: 'responsable', valueReference: { reference: 'Organization/' + o1 } });
          if (t1 && t1 !== tipo0) par.push({ name: 'tipoMantenimiento', valueCode: t1 });
          if (mf) par.push({ name: 'modoFalla', valueCode: mf });
          if (p1 !== t.priority) { if (sug && p1 !== sug && !mp) return aviso(msg, 'mal', 'Si no seguís la prioridad sugerida por el IRU, escribí el motivo.'); par.push({ name: 'prioridad', valueCode: p1 }); if (mp) par.push({ name: 'motivoPrioridad', valueString: mp }); }
          if (nt || (mo && !e1)) par.push({ name: 'nota', valueString: nt || mo });
          if (!par.length) return aviso(msg, 'mal', 'No hay cambios para guardar.');
          q('#oG').disabled = true;
          try { const r = await pim('POST', `Task/${id}/$transicion`, { resourceType: 'Parameters', parameter: par }, { 'If-Match': `W/"${t.meta.versionId}"` });
            const n = r.cuerpo?.resourceType === 'Task' ? r.cuerpo : (await pim('GET', 'Task/' + id)).cuerpo; const f = filas.find((y) => y.id === id);
            if (f) { const en = n.businessStatus?.coding?.[0]?.code || f.estado; if (en !== f.estado && res.por_estado) { res.por_estado[f.estado] = Math.max(0, (res.por_estado[f.estado] || 1) - 1); res.por_estado[en] = (res.por_estado[en] || 0) + 1; }
              Object.assign(f, { estado: en, estado_t: TXT[en] || en, org: n.owner?.display || f.org, org_id: (n.owner?.reference || '').split('/')[1] || f.org_id, prioridad: n.priority, prioridad_t: PRI[n.priority] || n.priority,
                tipo: t1 || f.tipo, tipo_t: t1 ? (tipos.find((v) => v.codigo === t1)?.texto || t1) : f.tipo_t, motivo: n.statusReason?.text || f.motivo }); }
            Nucleo.cerrar(); pinta(false); aviso($('#aMsg', el), 'ok', `Orden ${numCorto(x.numero)} actualizada.`); }
          catch (er) { aviso(msg, 'mal', er.message); q('#oG').disabled = false; } };
      }
      if (cal) { let n = x.estrellas || 0; p.querySelectorAll('#oS button').forEach((b) => b.onclick = () => { n = +b.dataset.n; p.querySelectorAll('#oS button').forEach((y) => y.classList.toggle('on', +y.dataset.n <= n)); });
        q('#oSG').onclick = async () => { if (!n) return aviso(msg, 'mal', 'Elegí de 1 a 5 estrellas.');
          try { await api('POST', `m/reportes/ordenes/${encodeURIComponent(id)}/calificar`, { estrellas: n, nota: q('#oSN').value }); const f = filas.find((y) => y.id === id); if (f) f.estrellas = n; Nucleo.cerrar(); pinta(false); aviso($('#aMsg', el), 'ok', `Valoración de la orden ${numCorto(x.numero)} guardada.`); }
          catch (er) { aviso(msg, 'mal', er.message); } }; }
    }
    await cargar();
  } });
})();
