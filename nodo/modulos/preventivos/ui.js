// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo preventivos · 2.0.0: plan semanal (semana, cronograma anual, relevamiento) y planes especiales (por visita o por uso).
// 1.6.0: activo, frecuencia, tipo, punto de uso y responsable se eligen de listas; formulario plegable.
(() => {
  const { h, fecha, aviso, tabla, panel, form, api, pim, puede, seccion, kpis } = Nucleo;
  const TIPOS = [['preventivo-sistematico', 'Preventivo sistemático (por calendario)'], ['preventivo-condicional', 'Preventivo condicional (por uso o estado)'], ['ensayo-reglamentario', 'Ensayo reglamentario'], ['mejora', 'Mejora']];
  // 2.2.0: solo activos en servicio (ni fuera de circulación ni cargados por error); «código · nombre · lugar», y se guardan para completar sistema y ubicación
  const DEVS = {};
  const opcionesActivo = async () => ((await pim('GET', 'Device?_count=500')).cuerpo.entry || []).map((x) => x.resource).filter((d) => d.status === 'active')
    .map((d) => { DEVS[d.id] = d; return [d.id, [d.identifier?.[0]?.value, d.deviceName?.[0]?.name || d.id, d.location?.display].filter(Boolean).join(' · ')]; }).sort((a, b) => a[1].localeCompare(b[1], 'es', { numeric: true }));
  const organizaciones = async () => ((await pim('GET', 'Organization?_count=300')).cuerpo.entry || []).map((x) => x.resource)
    .map((o) => [o.identifier?.[0]?.value || o.id, `${o.name || o.id}${o.identifier?.[0]?.value ? ' · ' + o.identifier[0].value : ''}`]).sort((a, b) => a[1].localeCompare(b[1]));
  const puntos = async () => { try { const u = (await api('GET', 'm/telemetria/ultimos')).ultimos; return [...new Set(u.map((x) => x.punto))].sort().map((p) => [p, p]); } catch (x) { return []; } };
  async function especiales(el) {
    const [p, c] = await Promise.all([api('GET', 'm/preventivos/planes'), api('GET', 'm/preventivos/calendario?dias=90')]);
    const hoy = new Date().toISOString().slice(0, 10);
    const pausados = p.planes.filter((x) => !+x.habilitado && (x.cada_dias || x.cada_valor));
    el.innerHTML = `<div class="tarjeta"><h2>Planes especiales</h2><p class="mini">Obras especiales, mejorativos del plan y planes por uso (horas de marcha, contadores): por fecha o por uso, con una orden por visita y su lista de tareas. El mantenimiento preventivo de rutina va en «Cronograma anual».</p>${tabla([{ t: 'Plan', v: (x) => x.titulo }, { t: 'Activo', v: (x) => x.activo_nombre || x.activo_id },
        { t: 'Frecuencia', v: (x) => [x.cada_dias ? `cada ${x.cada_dias} días` : '', x.cada_valor ? `cada ${x.cada_valor} de ${x.punto}` : ''].filter(Boolean).join(' · ') },
        { t: 'Próxima', html: true, v: (x) => x.proxima ? `<span class="et ${x.proxima < hoy ? 'rojo' : x.proxima === hoy ? 'nar' : ''}">${h(x.proxima)}</span>` : '—' },
        { t: 'Última', v: (x) => x.ultima || '—' }, { t: 'Estado', html: true, v: (x) => +x.habilitado ? '<span class="et verde">Habilitado</span>' : `<span class="et gris">En pausa${x.cada_dias || x.cada_valor ? '' : ' · sin frecuencia'}</span>` },
        { t: 'Orden abierta', v: (x) => x.orden_abierta || '' }, { t: 'Hechas', v: (x) => x.hechas }], p.planes, detalle)}
      ${puede('preventivos.editar') && pausados.length ? `<div class="acciones"><button class="b sec" id="pHab">Habilitar los ${pausados.length} planes en pausa que tienen frecuencia</button></div>
        <label class="fchk"><input type="checkbox" id="pHabR" checked> Los vencidos pasan a la próxima fecha de su ciclo (si no, cada uno genera su orden en la siguiente pasada, cada 5 minutos)</label><div id="pHabM"></div>` : ''}
      ${puede('preventivos.editar') ? seccion('Nuevo plan', form([{ n: 'titulo', t: 'Título', req: true, ph: 'Ej.: Limpieza de filtros' }, { n: 'activo_id', t: 'Equipo o instalación', tipo: 'opciones', cargar: opcionesActivo, req: true },
        { n: 'tipo_mant', t: 'Tipo', tipo: 'select', op: TIPOS }, { n: 'frecuencia', t: 'Frecuencia (calendario)', tipo: 'lista', lista: 'frecuencia', vacio: '— solo por uso —' },
        { n: 'proxima', t: 'Primera fecha', tipo: 'date', v: new Date().toISOString().slice(0, 10) }, { n: 'anticipacion_dias', t: 'Generar la orden antes', tipo: 'select', op: [['0', 'El mismo día'], ['1', '1 día antes'], ['3', '3 días antes'], ['7', '1 semana antes'], ['15', '15 días antes']] },
        { n: 'punto', t: 'Punto de uso', tipo: 'opciones', cargar: puntos, vacio: '— sin punto (solo calendario) —', ayuda: 'Contador de telemetría, p. ej. horas de marcha' }, { n: 'cada_valor', t: 'Cada cuánto uso', tipo: 'decimal' },
        { n: 'prioridad', t: 'Prioridad', tipo: 'select', op: [['routine', 'Rutina'], ['urgent', 'Urgente'], ['asap', 'Lo antes posible']] }, { n: 'responsable', t: 'Responsable', tipo: 'opciones', cargar: organizaciones, vacio: '— sin asignar —' },
        { n: 'tareas', t: 'Tareas (una por renglón: lista de verificación)', tipo: 'textarea' }], 'Crear plan', async (d, m) => {
          d.cada_dias = d.frecuencia ? +d.frecuencia : null; delete d.frecuencia; d.anticipacion_dias = +d.anticipacion_dias;
          if (!d.cada_dias && !(d.punto && d.cada_valor)) throw new Error('Elegí una frecuencia de calendario o un punto de uso con su cantidad');
          Object.keys(d).forEach((k) => (d[k] === '' || d[k] === null) && delete d[k]); await api('POST', 'm/preventivos/planes', d); aviso(m, 'ok', 'Plan creado.'); setTimeout(() => Nucleo.ir('preventivos'), 700); })) : ''}</div>
      <div class="tarjeta"><h2>Próximos 90 días</h2>${tabla([{ t: 'Fecha', v: (x) => x.fecha }, { t: 'Plan', v: (x) => x.titulo }, { t: 'Activo', v: (x) => x.activo || '' }], c.calendario.slice(0, 60))}</div>`;
    const bh = el.querySelector('#pHab');
    if (bh) bh.onclick = async () => { if (!confirm(`¿Habilitar ${pausados.length} planes? Los vencidos generan su orden en la siguiente pasada.`)) return;
      try { const r = await api('POST', 'm/preventivos/planes/habilitar', { ids: pausados.map((x) => x.id), reprogramar_vencidas: el.querySelector('#pHabR').checked });
        aviso(el.querySelector('#pHabM'), 'ok', `${r.habilitados} planes habilitados${r.reprogramadas ? `; ${r.reprogramadas} vencidos pasaron a su próxima fecha` : ''}.`); setTimeout(() => Nucleo.ir('preventivos'), 900); }
      catch (e) { aviso(el.querySelector('#pHabM'), 'mal', e.message); } };
  }
  async function detalle(x) {
    const p = panel(x.titulo, `<dl><dt>Activo</dt><dd>${h(x.activo_nombre)}</dd><dt>Tipo</dt><dd>${h((TIPOS.find((t) => t[0] === x.tipo_mant) || [0, x.tipo_mant])[1])}</dd><dt>Próxima</dt><dd>${h(x.proxima || '—')}</dd><dt>Tareas</dt><dd style="white-space:pre-wrap">${h(x.tareas || '—')}</dd></dl>
      ${puede('preventivos.editar') ? '<div class="acciones"><button class="b" id="pGen">Generar la orden ahora</button></div>' : ''}<div id="pMsg"></div>
      ${puede('preventivos.editar') ? `<h3 style="margin-top:14px">Frecuencia y estado${x.origen === 'importado' ? ' <span class="et gris">traído de otro sistema</span>' : ''}</h3>` + form([
        { n: 'frecuencia', t: 'Frecuencia', tipo: 'lista', lista: 'frecuencia', vacio: '— sin frecuencia de calendario —', v: x.cada_dias ? String(x.cada_dias) : '' },
        { n: 'proxima', t: 'Próxima fecha', tipo: 'date', v: x.proxima || '' },
        { n: 'habilitado', t: 'Estado', tipo: 'select', op: [['1', 'Habilitado: genera la orden en su fecha'], ['0', 'En pausa: no genera órdenes']], v: String(+x.habilitado) }], 'Guardar', async (d, m) => {
          const c = { cada_dias: d.frecuencia ? +d.frecuencia : null, proxima: d.proxima || null, habilitado: d.habilitado === '1' };
          await api('PUT', `m/preventivos/planes/${x.id}`, c); aviso(m, 'ok', 'Guardado.'); setTimeout(() => { Nucleo.cerrar(); Nucleo.ir('preventivos'); }, 700); }) : ''}`);
    p.insertAdjacentHTML('beforeend', '<h3 style="margin-top:14px">Órdenes de este plan</h3><div id="pOrd"><p class="mini">Cargando…</p></div>');
    api('GET', `m/preventivos/generadas?plan=${x.id}`).then((r) => { const z = p.querySelector('#pOrd'); if (!z) return;
      z.innerHTML = r.generadas.length ? tabla([{ t: 'Programada', v: (g) => g.programada || '—' }, { t: 'Disparo', v: (g) => g.disparo }, { t: 'Estado', v: (g) => ({ abierta: 'Abierta', hecha: 'Hecha', anulada: 'Anulada' })[g.estado] || g.estado },
        { t: 'Tareas', html: true, v: (g) => +g.tareas ? `${+g.hechas} de ${+g.tareas} hechas${+g.no_hechas ? ` · <span class="et rojo">${+g.no_hechas} sin hacer</span>` : ''}` : '—' }], r.generadas, (g) => registro(g, x))
        : '<p class="mini">Todavía no generó órdenes.</p>'; }).catch(() => null);
    const b = p.querySelector('#pGen');
    if (b) b.onclick = async () => { try { const r = await api('POST', `m/preventivos/planes/${x.id}/generar`); aviso(p.querySelector('#pMsg'), 'ok', 'Orden ' + r.orden + ' generada.'); } catch (e) { aviso(p.querySelector('#pMsg'), 'mal', e.message); } };
  }
  // 1.3.0: registro por tarea (una orden por visita; cada tarea queda con su resultado)
  const RES = [['pendiente', 'Pendiente'], ['hecha', 'Hecha'], ['no_hecha', 'No se hizo'], ['no_aplica', 'No aplica']];
  async function registro(g, plan) {
    const r = await api('GET', `m/preventivos/generadas/${g.id}/tareas`); const ed = puede('preventivos.registrar') && r.orden.estado !== 'anulada';
    const p = panel(`Tareas · ${plan.titulo}`, `<p class="mini">Orden programada para el ${h(r.orden.programada ? r.orden.programada.split('-').reverse().join('/') : '—')} · ${h(({ abierta: 'abierta', hecha: 'hecha', anulada: 'anulada' })[r.orden.estado] || r.orden.estado)}. Una tarea que no se hizo necesita el motivo.</p>
      ${r.tareas.length ? `<div class="regt">${r.tareas.map((t) => `<div class="fcampo" data-n="${t.n}" style="border-bottom:1px solid #e3e8ef;padding:6px 0"><label>${t.n}. ${h(t.tarea)}</label>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><select style="flex:0 0 160px;width:160px;min-height:40px" ${ed ? '' : 'disabled'}>${RES.map(([v, l]) => `<option value="${v}" ${t.resultado === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <input style="flex:1 1 220px;min-width:0;min-height:40px" placeholder="Nota u observación (obligatoria si no se hizo)" maxlength="300" value="${h(t.nota || '')}" ${ed ? '' : 'disabled'}></div>
        ${t.usuario ? `<small class="mini">${h(t.usuario)} · ${h(fecha(t.actualizado))}</small>` : ''}</div>`).join('')}</div>
        ${ed ? '<div class="acciones"><button class="b" id="rtG">Guardar</button><button class="b sec" id="rtT">Marcar todas como hechas</button></div>' : ''}` : '<p class="mini">El plan no tiene tareas cargadas.</p>'}<div id="rtM"></div>`);
    const filas = () => [...p.querySelectorAll('.regt [data-n]')].map((d) => ({ n: +d.dataset.n, resultado: d.querySelector('select').value, nota: d.querySelector('input').value }));
    const g1 = p.querySelector('#rtG'); if (g1) g1.onclick = async () => { try { const x = await api('PUT', `m/preventivos/generadas/${g.id}/tareas`, { tareas: filas() }); aviso(p.querySelector('#rtM'), 'ok', `Guardado: ${x.resumen.hecha} de ${x.resumen.total} hechas.`); } catch (e) { aviso(p.querySelector('#rtM'), 'mal', e.message); } };
    const g2 = p.querySelector('#rtT'); if (g2) g2.onclick = () => p.querySelectorAll('.regt select').forEach((s) => { if (s.value === 'pendiente') s.value = 'hecha'; });
  }
  // ================================================================== 2.0.0 · plan preventivo por semanas
  // Semana 1 = la del 1 de enero (lunes a domingo); 53 o 54 por año. Frecuencias en semanas fijas. Una orden por equipo y semana.
  const FRC = { D: ['Diaria', '#7e57c2', '#fff'], SM: ['Semanal', '#ec407a', '#fff'], Q: ['Quincenal', '#ff7043', '#fff'], M: ['Mensual', '#e53935', '#fff'], B: ['Bimestral', '#90caf9', '#0d47a1'],
    T: ['Trimestral', '#fdd835', '#4e342e'], S: ['Semestral', '#bdbdbd', '#212121'], A: ['Anual', '#43a047', '#fff'], '2A': ['Cada 2 años', '#fff59d', '#4e342e'] };
  const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
  const dmy = (x) => (x ? x.slice(0, 10).split('-').reverse().join('/') : '—');
  const hoyIso = () => { const d = new Date(); return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };
  const semanaDe = (iso) => { const d = new Date(iso + 'T12:00:00'); const e = new Date(d.getFullYear(), 0, 1, 12); const dw = (e.getDay() + 6) % 7; return [d.getFullYear(), Math.floor((Math.round((d - e) / 864e5) + dw) / 7) + 1]; };
  const etq = (f) => (f && FRC[f] ? `<span class="pmpf" style="background:${FRC[f][1]};color:${FRC[f][2]}" title="${FRC[f][0]}">${f}</span>` : '<span class="pmpf" style="background:#eceff1;color:#546e7a" title="Sin frecuencia">—</span>');
  const DIAS = ['Lu', 'Ma', 'Mi', 'Ju', 'Vi', 'Sá', 'Do'];
  const RESN = [['pendiente', 'Pendiente'], ['hecha', 'Hecha'], ['no_hecha', 'No se hizo'], ['no_aplica', 'No aplica']];
  const [Y0, W0] = semanaDe(hoyIso());
  const PV = { tab: 'semana', anio: Y0, semana: W0, cAnio: Y0, f: { sistema: '', ubicacion: '', frecuencia: '', marcas: '', q: '' } };
  function estilos() {
    if (document.getElementById('pmp-css')) return;
    const st = document.createElement('style'); st.id = 'pmp-css';
    st.textContent = `.pmpf{display:inline-block;min-width:26px;padding:1px 5px;border-radius:6px;font-size:11px;font-weight:700;text-align:center}
      .pmpw{overflow-x:auto;max-width:100%;border:1px solid #dfe6ee;border-radius:10px;background:#fff}
      table.pmpg{display:table;width:auto;border-collapse:separate;border-spacing:0;font-size:12px;min-width:100%}
      .pmpg th,.pmpg td{border-bottom:1px solid #eef2f6;border-right:1px solid #f2f5f8;padding:0;text-align:center;white-space:nowrap}
      .pmpg thead th{background:#e8f1fb;color:#0d47a1;font-weight:600;position:sticky;top:0;z-index:1}
      .pmpg .pmpi{position:sticky;left:0;background:#fff;text-align:left;padding:4px 8px;min-width:230px;max-width:300px;white-space:normal;z-index:2;cursor:pointer}
      .pmpg thead .pmpi{background:#e8f1fb;z-index:3}
      .pmpg td.c{width:22px;min-width:22px;height:24px;cursor:default}
      .pmpg td.c.ed{cursor:pointer}.pmpg td.c.ed:hover{background:#e3f2fd}
      .pmpg td.c.on{font-weight:700;font-size:10px}
      .pmpg .act{outline:2px solid #e53935;outline-offset:-2px}
      .pmpg tr.sis td{background:#f5f8fc;text-align:left;font-weight:700;color:#1e3a5f;padding:5px 8px}
      .pmpeq{border-left:4px solid #1e88e5}.pmpeq h3{margin:0 0 4px}.pmpt{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:8px 0;border-top:1px solid #eef2f6}
      .pmpt .tx{flex:1 1 260px;min-width:0}.pmpt select{flex:0 0 140px;width:140px;min-height:38px}.pmpt input[type=date]{flex:0 0 150px;width:150px;min-height:38px}.pmpt input.nt{flex:1 1 200px;min-width:0;min-height:38px}
      .pmpd{display:flex;gap:4px;flex:1 1 100%;flex-wrap:wrap}.pmpd label{display:flex;flex-direction:column;align-items:center;min-width:38px;padding:3px 2px;border:1px solid #dfe6ee;border-radius:8px;font-size:11px;color:#455a64;cursor:pointer;background:#fff}
      .pmpd label.hoy{border-color:#1e88e5;box-shadow:0 0 0 1px #1e88e5}.pmpd label.fuera{opacity:.35;cursor:default}.pmpd input{width:20px;height:20px;margin:2px 0 0}.pmpd small{font-size:10px;color:#78909c}
      .pmpsin{background:#fff8e1;border:1px solid #ffe082;border-radius:10px;padding:8px 12px;margin-bottom:10px}
      .pmprel td input,.pmprel td select{width:100%;min-width:90px;min-height:34px}
      .pmpfil{display:flex;flex-wrap:wrap;gap:4px 12px}.pmpfil .fcampo{flex:1 1 140px;min-width:0}.pmpfil .fcampo input,.pmpfil .fcampo select{width:100%}
      .pmpnav{display:flex;gap:6px}.pmpnav .b{flex:1 1 auto;width:auto;min-width:0;padding-left:10px;padding-right:10px}
      @media (max-width:640px){.pmpg .pmpi{min-width:150px;max-width:180px;font-size:11px}.pmpnav{width:100%}
        table.pmprel,table.pmprel tbody,table.pmprel tr,table.pmprel td{display:block;width:100%}table.pmprel{min-width:0}table.pmprel thead{display:none}
        table.pmprel tr{border:1px solid #dfe6ee;border-radius:10px;padding:8px;margin:0 0 8px}table.pmprel td{border:0;padding:3px 0;text-align:left}
        table.pmprel td::before{content:attr(data-l);display:block;font-size:11px;color:#607d8b;font-weight:600}.pmpw.rel{border:0;overflow:visible}}`;
    document.head.appendChild(st);
  }
  Nucleo.pestana({ id: 'preventivos', titulo: 'Preventivos', orden: 30, cap: 'preventivos.ver', icono: 'reloj', desc: 'Plan semanal, cronograma anual, relevamiento y planes especiales', render: async (el) => {
    estilos();
    const tabs = [['semana', 'Semana'], ['cronograma', 'Cronograma anual'], ...(puede('preventivos.relevar') ? [['relevamiento', 'Relevamiento']] : []), ['especiales', 'Planes especiales']];
    if (!tabs.some((t) => t[0] === PV.tab)) PV.tab = 'semana';
    el.innerHTML = `<div class="pest">${tabs.map(([k, t]) => `<button data-t="${k}" class="${k === PV.tab ? 'activo' : ''}">${t}</button>`).join('')}</div><div id="pvC"></div>`;
    el.querySelectorAll('.pest button').forEach((b) => b.onclick = () => { PV.tab = b.dataset.t; Nucleo.ir('preventivos'); });
    const c = el.querySelector('#pvC');
    if (PV.tab === 'semana') return vistaSemana(c);
    if (PV.tab === 'cronograma') return vistaCronograma(c);
    if (PV.tab === 'relevamiento') return vistaRelevamiento(c);
    return especiales(c);
  } });

  // ------------------------------------------------------------------ semana
  async function vistaSemana(c) {
    const r = await api('GET', `m/preventivos/pmp/semana?anio=${PV.anio}&semana=${PV.semana}`); const t = r.totales;
    const ed = puede('preventivos.registrar'); const sinOrden = r.equipos.filter((e) => !e.orden_id).length;
    c.innerHTML = `<div class="tarjeta"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;justify-content:space-between">
        <h2 style="margin:0">Semana ${r.semana} de ${r.anio} <small class="mini">· del ${dmy(r.desde)} al ${dmy(r.hasta)}</small></h2>
        <div class="pmpnav"><button class="b sec" id="swA" title="Semana anterior">‹ Anterior</button><button class="b sec" id="swH">Esta semana</button><button class="b sec" id="swS" title="Semana siguiente">Siguiente ›</button></div></div>
      ${kpis([{ t: 'Tareas', n: t.tareas, sub: `${r.equipos.length} equipos`, icono: 'reloj', color: '#1e88e5' }, { t: 'Hechas', n: t.hecha, sub: `${t.a_tiempo} dentro de la semana`, icono: 'ok', color: '#2e7d32' },
        { t: 'Pendientes', n: t.pendiente, icono: 'reloj', color: '#ef6c00' }, { t: 'No se hicieron', n: t.no_hecha, sub: 'con motivo', icono: 'alarmas', color: '#c62828' }, { t: 'No aplica', n: t.no_aplica, icono: 'modulo', color: '#546e7a' }, ...(t.dias_programados ? [{ t: 'Diarias', n: `${t.dias_hechos} de ${t.dias_programados}`, sub: 'días hechos', icono: 'ok', color: '#7e57c2' }] : [])])}
      ${sinOrden ? `<div class="pmpsin">${sinOrden} equipo(s) con tareas marcadas todavía sin orden. Las órdenes se generan solas desde el primer día de la semana${puede('preventivos.editar') ? '; también podés generarlas ahora.' : '.'}
        ${puede('preventivos.editar') ? '<div class="acciones" style="margin:6px 0 0"><button class="b" id="swG">Generar las órdenes de la semana</button></div>' : ''}<div id="swGM"></div></div>` : ''}
      ${r.equipos.length ? '' : '<p class="mini">No hay tareas marcadas para esta semana en el cronograma.</p>'}</div>
      ${r.equipos.map((e, i) => `<div class="tarjeta pmpeq" data-i="${i}"><h3>${h(e.activo_nombre || '')} <small class="mini">${h(e.activo_codigo || '')}</small></h3>
        <div class="mini">${h([e.sistema, e.ubicacion].filter(Boolean).join(' · '))}${e.orden_id ? ` · Orden <b>${h(e.numero || '')}</b> · ${h(({ abierta: 'abierta', hecha: 'cerrada', anulada: 'anulada' })[e.orden_estado] || e.orden_estado || '')}` : ' · <span class="et nar">sin orden todavía</span>'}</div>
        ${e.tareas.map((x) => `<div class="pmpt" data-t="${x.id}"><div class="tx">${etq(x.frecuencia)} <b>${h(x.parte || '')}</b>${x.parte ? ' · ' : ''}${h(x.preventivo)}${x.predictivo ? `<br><small class="mini">Predictivo: ${h(x.predictivo)}</small>` : ''}${x.personal ? `<small class="mini"> · ${h(x.personal)}</small>` : ''}
            ${x.usuario ? `<br><small class="mini">${h(x.usuario)} · ${h(fecha(x.actualizado))}</small>` : ''}</div>
          ${e.orden_id ? `<select ${ed ? '' : 'disabled'}>${RESN.map(([v, l]) => `<option value="${v}" ${x.resultado === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
            ${x.dias ? `<input type="date" hidden value="${h(x.fecha_real || '')}">` : `<input type="date" title="Fecha real en que se hizo" value="${h(x.fecha_real || '')}" max="${hoyIso()}" ${ed ? '' : 'disabled'} ${x.resultado === 'hecha' ? '' : 'style="visibility:hidden"'}>`}
            <input class="nt" placeholder="Nota (obligatoria si no se hizo)" maxlength="300" value="${h(x.nota || '')}" ${ed ? '' : 'disabled'}>
            ${x.dias ? `<div class="pmpd" title="Marcá cada día en que se hizo">${x.dias.map((d, k) => `<label class="${d.en_rango ? '' : 'fuera'} ${d.fecha === hoyIso() ? 'hoy' : ''}" title="${dmy(d.fecha)}${d.en_rango ? '' : ' (fuera del año)'}">${DIAS[k]}<small>${d.fecha.slice(8)}</small><input type="checkbox" data-d="${k}" data-f="${d.fecha}" ${d.hecho ? 'checked' : ''} ${ed && d.en_rango && d.fecha <= hoyIso() ? '' : 'disabled'}></label>`).join('')}</div>` : ''}` : ''}</div>`).join('')}
        ${e.orden_id && ed ? `<div class="acciones"><button class="b" data-g="${e.orden_id}">Guardar</button><button class="b sec" data-todas="${i}">Todas hechas hoy</button></div><div class="mm"></div>` : ''}</div>`).join('')}`;
    const mover = () => { if (PV.semana < r.semanas) PV.semana++; else { PV.anio++; PV.semana = 1; } vistaSemana(c); };
    c.querySelector('#swA').onclick = async () => { if (PV.semana > 1) { PV.semana--; return vistaSemana(c); } PV.anio--; PV.semana = (await api('GET', `m/preventivos/pmp/semana?anio=${PV.anio}&semana=1`)).semanas; vistaSemana(c); };
    c.querySelector('#swS').onclick = mover;
    c.querySelector('#swH').onclick = () => { [PV.anio, PV.semana] = semanaDe(hoyIso()); vistaSemana(c); };
    const g = c.querySelector('#swG'); if (g) g.onclick = async () => { g.disabled = true; try { const x = await api('POST', 'm/preventivos/pmp/semana/generar', { anio: r.anio, semana: r.semana }); aviso(c.querySelector('#swGM'), 'ok', `${x.creadas} órdenes generadas.${x.errores.length ? ' Con problemas: ' + x.errores.map((e) => e.activo + ': ' + e.error).join(' · ') : ''}`); setTimeout(() => vistaSemana(c), 900); } catch (e) { aviso(c.querySelector('#swGM'), 'mal', e.message); g.disabled = false; } };
    c.querySelectorAll('.pmpt select').forEach((s) => s.onchange = () => { const f = s.parentElement.querySelector('input[type=date]'); if (f.hidden) return; f.style.visibility = s.value === 'hecha' ? 'visible' : 'hidden'; if (s.value === 'hecha' && !f.value) f.value = hoyIso() < r.desde ? r.desde : (hoyIso() > r.hasta ? r.hasta : hoyIso()); });
    const diaria = (d) => { const cs = [...d.querySelectorAll('.pmpd input')]; if (!cs.length) return; const en = cs.filter((x) => !x.closest('label').classList.contains('fuera')); const s = d.querySelector('select');
      if (en.every((x) => x.checked) && s.value !== 'no_aplica') s.value = 'hecha'; else if (s.value === 'hecha') s.value = 'pendiente'; };
    c.querySelectorAll('.pmpd input').forEach((x) => x.onchange = () => diaria(x.closest('.pmpt')));
    c.querySelectorAll('[data-todas]').forEach((b) => b.onclick = () => b.closest('.pmpeq').querySelectorAll('.pmpt').forEach((d) => {
      const s = d.querySelector('select'); const hoy = d.querySelector(`.pmpd input[data-f="${hoyIso()}"]`);
      if (d.querySelector('.pmpd')) { if (hoy && !hoy.disabled) { hoy.checked = true; diaria(d); } return; }
      if (s.value === 'pendiente') { s.value = 'hecha'; s.onchange(); } }));
    c.querySelectorAll('[data-g]').forEach((b) => b.onclick = async () => { const card = b.closest('.pmpeq'); const m = card.querySelector('.mm');
      const tareas = [...card.querySelectorAll('.pmpt')].map((d) => ({ tarea_id: +d.dataset.t, resultado: d.querySelector('select').value, fecha_real: d.querySelector('input[type=date]').value || null, nota: d.querySelector('.nt').value,
        ...(d.querySelector('.pmpd') ? { dias: [...d.querySelectorAll('.pmpd input')].map((x) => x.checked) } : {}) }));
      try { const x = await api('PUT', `m/preventivos/pmp/ordenes/${b.dataset.g}`, { tareas }); aviso(m, 'ok', `Guardado${x.pendientes ? `; quedan ${x.pendientes} pendientes` : '; todas resueltas'}.`); if (card.querySelector('.pmpd')) setTimeout(() => vistaSemana(c), 1200); } catch (e) { aviso(m, 'mal', e.message); } });
  }

  // ------------------------------------------------------------------ cronograma anual (la grilla de la planilla)
  async function vistaCronograma(c) {
    const f = PV.f; const q = new URLSearchParams({ anio: PV.cAnio, ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)) });
    const r = await api('GET', `m/preventivos/pmp/cronograma?${q}`); const ed = puede('preventivos.editar'); const N = r.semanas;
    const meses = []; r.meses.forEach((m, i) => { if (!meses.length || meses[meses.length - 1].m !== m) meses.push({ m, n: 1, i }); else meses[meses.length - 1].n++; });
    const filasHtml = []; let sis;
    r.tareas.forEach((t) => {
      if ((t.sistema ?? '') !== sis) { sis = t.sistema ?? ''; filasHtml.push(`<tr class="sis"><td class="pmpi" colspan="2">${h(sis || 'Sin sistema')}</td><td colspan="${N}"></td></tr>`); }
      const set = new Set(t.semanas_marcadas); const col = FRC[t.frecuencia] || ['', '#90a4ae', '#fff'];
      let celdas = ''; for (let w = 1; w <= N; w++) { const on = set.has(w); celdas += `<td class="c${ed ? ' ed' : ''}${on ? ' on' : ''}${w === r.actual ? ' act' : ''}" data-w="${w}" ${on ? `style="background:${col[1]};color:${col[2]}"` : ''}>${on ? (t.frecuencia || '•') : ''}</td>`; }
      filasHtml.push(`<tr data-id="${t.id}"><td class="pmpi"><b>${h(t.activo_nombre || '')}</b>${t.parte ? ' · ' + h(t.parte) : ''}<br>${h(t.preventivo)}${t.ubicacion ? `<br><small class="mini">${h(t.ubicacion)}</small>` : ''}</td><td>${etq(t.frecuencia)}</td>${celdas}</tr>`);
    });
    const anios = [Y0 - 1, Y0, Y0 + 1, Y0 + 2];
    c.innerHTML = `<div class="tarjeta"><h2>Cronograma anual ${r.anio} <small class="mini">· ${N} semanas · ${r.tareas.length} tareas${r.actual ? ` · semana actual: ${r.actual}` : ''}</small></h2>
      <div class="fgrid pmpfil"><div class="fcampo"><label>Año</label><select id="cgA">${anios.map((a) => `<option ${a === r.anio ? 'selected' : ''}>${a}</option>`).join('')}</select></div>
        <div class="fcampo"><label>Sistema</label><select id="cgS"><option value="">Todos</option>${r.sistemas.map((x) => `<option ${x === f.sistema ? 'selected' : ''}>${h(x)}</option>`).join('')}</select></div>
        <div class="fcampo"><label>Ubicación</label><select id="cgU"><option value="">Todas</option>${r.ubicaciones.map((x) => `<option ${x === f.ubicacion ? 'selected' : ''}>${h(x)}</option>`).join('')}</select></div>
        <div class="fcampo"><label>Frecuencia</label><select id="cgF"><option value="">Todas</option><option value="-" ${f.frecuencia === '-' ? 'selected' : ''}>Sin frecuencia</option>${Object.entries(FRC).map(([k, v]) => `<option value="${k}" ${k === f.frecuencia ? 'selected' : ''}>${v[0]}</option>`).join('')}</select></div>
        <div class="fcampo"><label>Semanas en ${r.anio}</label><select id="cgK"><option value="">Todas</option><option value="0" ${f.marcas === '0' ? 'selected' : ''}>Sin marcar</option><option value="1" ${f.marcas === '1' ? 'selected' : ''}>Con semanas marcadas</option></select></div>
        <div class="fcampo"><label>Buscar</label><input id="cgQ" value="${h(f.q)}" placeholder="Equipo, parte o tarea"></div></div>
      <div class="mini" style="margin:6px 0">${Object.entries(FRC).map(([k, v]) => `${etq(k)} ${v[0]}`).join(' · ')}${ed ? ' · Tocá una celda para marcar o desmarcar esa semana; tocá la tarea para editarla o marcar el año desde una semana.' : ''}</div>
      <div class="acciones">${ed ? '<button class="b" id="cgN">Nueva tarea</button><button class="b sec" id="cgC">Copiar al año siguiente</button>' : ''}<button class="b sec" id="cgP">Imprimir</button></div><div id="cgM"></div>
      ${ed && r.tareas.length ? `<details class="desp" id="cgLote"><summary><span class="mas">＋</span>Marcar en bloque las ${r.tareas.length} tareas de esta vista</summary><div class="desp-c">
        <p class="mini">Filtrá primero (por ejemplo «Sin frecuencia» y un sistema o un equipo). Con frecuencia, el año ${r.anio} se marca desde la semana de inicio; sin frecuencia, se marca solo esa semana.</p>
        <div class="fgrid pmpfil"><div class="fcampo"><label>Frecuencia</label><select id="lgF"><option value="__igual">— sin cambiar —</option>${Object.entries(FRC).map(([k, v]) => `<option value="${k}">${v[0]}</option>`).join('')}<option value="">Quitar la frecuencia</option></select></div>
          <div class="fcampo"><label>Semana de inicio en ${r.anio}</label><input id="lgW" type="number" min="1" max="${N}" placeholder="1 a ${N}"></div></div>
        <div class="acciones"><button class="b" id="lgOk">Aplicar a las ${r.tareas.length} tareas</button></div><div id="lgM"></div></div></details>` : ''}
      ${r.tareas.length ? `<div class="pmpw"><table class="pmpg"><thead><tr><th class="pmpi" rowspan="2">Equipo · parte · preventivo</th><th rowspan="2">Frec.</th>${meses.map((m) => `<th colspan="${m.n}">${MESES[m.m - 1].slice(0, m.n > 2 ? 10 : 3)}</th>`).join('')}</tr>
        <tr>${Array.from({ length: N }, (_, i) => `<th class="${i + 1 === r.actual ? 'act' : ''}">${i + 1}</th>`).join('')}</tr></thead><tbody>${filasHtml.join('')}</tbody></table></div>`
        : '<p class="mini">No hay tareas con esos filtros. Se cargan con «Nueva tarea», desde «Relevamiento» o desde Configuración › Migración (hoja de cálculo).</p>'}</div>`;
    const re = () => vistaCronograma(c);
    c.querySelector('#cgA').onchange = (e) => { PV.cAnio = +e.target.value; re(); };
    [['#cgS', 'sistema'], ['#cgU', 'ubicacion'], ['#cgF', 'frecuencia'], ['#cgK', 'marcas']].forEach(([s, k]) => c.querySelector(s).onchange = (e) => { f[k] = e.target.value; re(); });
    let tq; c.querySelector('#cgQ').oninput = (e) => { clearTimeout(tq); tq = setTimeout(() => { f.q = e.target.value.trim(); re(); }, 500); };
    c.querySelectorAll('tr[data-id] .pmpi').forEach((td) => td.onclick = () => panelTarea(r.tareas.find((t) => t.id === +td.parentElement.dataset.id), r.anio, re));
    if (ed) c.querySelectorAll('td.c.ed').forEach((td) => td.onclick = async () => {
      const id = +td.parentElement.dataset.id; const t = r.tareas.find((x) => x.id === id);
      try { const x = await api('POST', `m/preventivos/pmp/tareas/${id}/semana`, { anio: r.anio, semana: +td.dataset.w }); t.semanas_marcadas = x.semanas; const on = x.semanas.includes(+td.dataset.w);
        const col = FRC[t.frecuencia] || ['', '#90a4ae', '#fff']; td.classList.toggle('on', on); td.style.background = on ? col[1] : ''; td.style.color = on ? col[2] : ''; td.textContent = on ? (t.frecuencia || '•') : ''; }
      catch (e) { aviso(c.querySelector('#cgM'), 'mal', e.message); } });
    const bn = c.querySelector('#cgN'); if (bn) bn.onclick = () => panelTarea(null, r.anio, re);
    const lg = c.querySelector('#lgOk'); if (lg) lg.onclick = async () => { const fr = c.querySelector('#lgF').value, w = +c.querySelector('#lgW').value || 0; const m = c.querySelector('#lgM');
      if (fr === '__igual' && !w) return aviso(m, 'mal', 'Elegí una frecuencia, una semana de inicio o las dos.');
      if (!confirm(`¿Aplicar a las ${r.tareas.length} tareas de esta vista${fr === '' ? ' sin frecuencia' : fr !== '__igual' ? ` la frecuencia «${FRC[fr][0]}»` : ''}${w ? ` y marcar ${r.anio} desde la semana ${w}` : ''}? Las marcas puestas a mano se respetan.`)) return;
      try { const x = await api('POST', 'm/preventivos/pmp/tareas/lote', { ids: r.tareas.map((t) => t.id), frecuencia: fr, anio: r.anio, semana_inicio: w || null }); aviso(m, 'ok', `${x.tareas} tareas: ${x.frecuencia} con frecuencia nueva, ${x.marcadas} marcadas.`); setTimeout(re, 900); } catch (e) { aviso(m, 'mal', e.message); } };
    const bc = c.querySelector('#cgC'); if (bc) bc.onclick = async () => { if (!confirm(`¿Armar el cronograma de ${r.anio + 1} copiando la semana de inicio de cada tarea de ${r.anio}? Las tareas que ya tienen semanas en ${r.anio + 1} no se tocan.`)) return;
      try { const x = await api('POST', 'm/preventivos/pmp/copiar', { anio: r.anio }); aviso(c.querySelector('#cgM'), 'ok', `Cronograma ${x.anio}: ${x.tareas} tareas copiadas${x.ya_tenian ? `; ${x.ya_tenian} ya tenían semanas y no se tocaron` : ''}.`); } catch (e) { aviso(c.querySelector('#cgM'), 'mal', e.message); } };
    c.querySelector('#cgP').onclick = () => { document.querySelectorAll('.soloimp').forEach((e) => e.remove()); const d = document.createElement('div'); d.className = 'imprimible soloimp';
      d.innerHTML = `<style>@page{size:A3 landscape;margin:8mm}.pmpg{font-size:8px}.pmpg .pmpi{min-width:160px}</style><h1>Cronograma de mantenimiento preventivo ${r.anio}</h1><div>${h(Nucleo.sesion?.nodo?.nombre || '')} · ${r.tareas.length} tareas · impreso el ${fecha(new Date().toISOString())}</div>${c.querySelector('.pmpw')?.innerHTML || ''}`;
      document.body.appendChild(d); window.print(); };
  }
  function panelTarea(t, anio, alGuardar) {
    const nueva = !t; t = t || {};
    const p = panel(nueva ? 'Nueva tarea preventiva' : `${t.activo_nombre} · ${t.preventivo}`, form([
      { n: 'activo', t: 'Equipo o instalación (ID unidad)', tipo: 'opciones', cargar: opcionesActivo, req: true, v: t.activo_id || '', vacio: '— elegí de la lista —', ancho: 'todo',
        ayuda: 'Los activos en servicio del Inventario. Si no está, dalo de alta en Inventario (o importá tu planilla) y volvé a abrir esta tarea' },
      { n: 'sistema', t: 'Sistema', v: t.sistema || '', ph: 'Ej.: Eléctrico, Gases medicinales, Contraincendio' }, { n: 'ubicacion', t: 'Ubicación', v: t.ubicacion || '', ph: 'Ej.: Edificio Central' },
      { n: 'instalacion', t: 'Instalación', v: t.instalacion || '' }, { n: 'sector', t: 'Sector', v: t.sector || '' }, { n: 'categoria', t: 'Categoría', v: t.categoria || '' },
      { n: 'parte', t: 'Parte o componente', v: t.parte || '', ph: 'Ej.: Motor / Rodamientos' }, { n: 'preventivo', t: 'Tarea preventiva', req: true, v: t.preventivo || '' },
      { n: 'predictivo', t: 'Predictivo', v: t.predictivo || '' }, { n: 'personal', t: 'Personal', v: t.personal || '', ph: 'Técnico, Operador, Empresa tercerizada' },
      { n: 'hoja_vida', t: 'Hoja de vida', v: t.hoja_vida || '' }, { n: 'inspeccion', t: 'Inspección', v: t.inspeccion || '', ph: 'Ej.: Ruta' }, { n: 'insumos', t: 'Insumos', v: t.insumos || '' },
      { n: 'frecuencia', t: 'Frecuencia', tipo: 'select', op: [['', '— sin frecuencia (marcar a mano) —'], ...Object.entries(FRC).map(([k, v]) => [k, `${v[0]} (cada ${({ D: 1, SM: 1, Q: 2, M: 4, B: 8, T: 13, S: 26, A: 52, '2A': 104 })[k]} semana${k === 'D' || k === 'SM' ? '' : 's'})`])], v: t.frecuencia || '' },
      { n: 'responsable', t: 'Empresa responsable', tipo: 'opciones', cargar: organizaciones, vacio: '— sin asignar —', v: t.responsable || '' },
      ...(nueva ? [{ n: 'semana_inicio', t: `Primera semana en ${anio}`, tipo: 'number', ph: '1 a 53', ayuda: 'Desde ahí se marcan las siguientes según la frecuencia' }]
        : [{ n: 'activa', t: 'Estado', tipo: 'select', op: [['1', 'Activa'], ['0', 'Dada de baja (no genera órdenes)']], v: String(+t.activa) }])], nueva ? 'Crear tarea' : 'Guardar', async (d, m) => {
      const c = { ...d }; if (!nueva) { c.activa = d.activa === '1'; } else { if (d.semana_inicio) { c.anio = anio; c.semana_inicio = +d.semana_inicio; } else delete c.semana_inicio; }
      await api(nueva ? 'POST' : 'PUT', nueva ? 'm/preventivos/pmp/tareas' : `m/preventivos/pmp/tareas/${t.id}`, c); aviso(m, 'ok', 'Guardado.'); setTimeout(() => { Nucleo.cerrar(); alGuardar(); }, 600); },
      { alCargar: (f) => {   // al elegir el activo se completan sistema y ubicación si están vacíos
        const s = f.elements.activo; if (s.options.length <= 1) aviso(f.querySelector('.fmsg'), 'info', 'Todavía no hay activos en servicio: cargalos en Inventario (de a uno o importando una planilla).');
        s.addEventListener('change', () => { const d = DEVS[s.value]; if (!d) return;
          const sis = d.type?.coding?.[0]?.display || d.type?.text || ''; if (sis && !f.elements.sistema.value) f.elements.sistema.value = sis;
          if (d.location?.display && !f.elements.ubicacion.value) f.elements.ubicacion.value = d.location.display; });
      } }));
    if (!nueva && puede('preventivos.editar')) {
      p.insertAdjacentHTML('beforeend', `<h3 style="margin-top:14px">Cronograma ${anio}</h3><p class="mini">Semanas marcadas: ${t.semanas_marcadas.length ? t.semanas_marcadas.join(', ') : 'ninguna'}.</p>
        <div class="fgrid pmpfil"><div class="fcampo"><label>Marcar el año desde la semana</label><input id="ptW" type="number" min="1" max="54" value="${t.semanas_marcadas[0] || ''}"></div></div>
        <div class="acciones"><button class="b sec" id="ptM">Marcar según la frecuencia</button></div><div id="ptMM"></div>`);
      p.querySelector('#ptM').onclick = async () => { try { const x = await api('POST', `m/preventivos/pmp/tareas/${t.id}/marcar`, { anio, semana_inicio: +p.querySelector('#ptW').value }); aviso(p.querySelector('#ptMM'), 'ok', `Semanas: ${x.semanas.join(', ')}.`); alGuardar(); } catch (e) { aviso(p.querySelector('#ptMM'), 'mal', e.message); } };
    }
  }

  // ------------------------------------------------------------------ relevamiento en recorrida (funciona sin señal: lo cargado queda en este dispositivo hasta enviarlo)
  const LK = 'iproy_pmp_relevamientos', LC = 'iproy_pmp_catalogos';
  const leerL = (k, def) => { try { return JSON.parse(localStorage.getItem(k) || '') || def; } catch (x) { return def; } };
  const guardarL = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); return true; } catch (x) { return false; } };
  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (ch) => { const r = Math.random() * 16 | 0; return (ch === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }));
  async function catalogosRel() {
    try { const [pl, cs] = await Promise.all([api('GET', 'm/preventivos/pmp/plantillas'), pim('GET', 'CodeSystem/pim-sistema')]);
      const v = { plantillas: pl.plantillas, clases: (cs.cuerpo.concept || []).map((x) => [x.code, x.display]), guardado: new Date().toISOString() }; guardarL(LC, v); return v; }
    catch (x) { return leerL(LC, { plantillas: [], clases: [], guardado: null }); }
  }
  async function vistaRelevamiento(c, abrir) {
    const cat = await catalogosRel(); const loc = leerL(LK, []); let srv = null;
    try { srv = (await api('GET', 'm/preventivos/pmp/relevamientos')).relevamientos; } catch (x) { srv = null; }
    const gm = puede('preventivos.editar'); const yo = Nucleo.sesion?.usuario?.usuario || '';
    const pend = (srv || []).filter((r) => r.estado === 'enviado'); const mios = (srv || []).filter((r) => r.usuario === yo || !gm);
    c.innerHTML = `${srv === null ? '<div class="pmpsin">Sin conexión con el nodo: podés seguir relevando; todo queda guardado en este dispositivo y lo enviás al volver la señal.</div>' : ''}
      <div class="tarjeta"><h2>Relevamiento en recorrida</h2><p class="mini">Cargá cada equipo o instalación con sus partes y tareas preventivas. Elegí una plantilla por tipo de equipo para empezar; todo es editable. Lo que envíes lo revisa un Gestor antes de pasar al plan.</p>
        <div class="acciones"><button class="b" id="rvN">Nuevo relevamiento</button></div>
        ${loc.length ? `<h3>En este dispositivo (sin enviar)</h3>${tabla([{ t: 'Unidad', v: (x) => [x.datos.unidad.codigo, x.datos.unidad.nombre].filter(Boolean).join(' · ') || '(sin nombre)' }, { t: 'Tareas', v: (x) => x.datos.tareas.filter((t) => t.preventivo).length }, { t: 'Guardado', v: (x) => fecha(x.guardado) }], loc, (x) => editor(c, x))}` : ''}
        ${mios.length ? `<h3>Enviados</h3>${tabla([{ t: 'Unidad', v: (x) => x.titulo }, { t: 'Tareas', v: (x) => x.tareas }, { t: 'Estado', html: true, v: (x) => `<span class="et ${({ enviado: 'nar', aprobado: 'verde', rechazado: 'rojo' })[x.estado]}">${({ enviado: 'en revisión', aprobado: 'aprobado', rechazado: 'para corregir' })[x.estado]}</span>${x.nota_revision ? ' ' + h(x.nota_revision) : ''}` }, { t: 'Enviado', v: (x) => fecha(x.actualizado || x.creado) }], mios, async (x) => { if (x.estado === 'rechazado') { const r = (await api('GET', `m/preventivos/pmp/relevamientos/${x.id}`)).relevamiento; editor(c, { uid: r.uid, datos: r.datos, guardado: new Date().toISOString(), nota: r.nota_revision }); } })}` : ''}</div>
      ${gm && pend.length ? `<div class="tarjeta"><h2>Para revisar <small class="mini">· ${pend.length}</small></h2>${tabla([{ t: 'Unidad', v: (x) => x.titulo }, { t: 'Relevó', v: (x) => x.usuario }, { t: 'Tareas', v: (x) => x.tareas }, { t: 'Enviado', v: (x) => fecha(x.actualizado || x.creado) }], pend, (x) => revisar(c, x.id))}</div>` : ''}`;
    c.querySelector('#rvN').onclick = () => editor(c, { uid: uuid(), datos: { unidad: {}, tareas: [] }, guardado: null });
    if (abrir) editor(c, abrir);
  }
  function editor(c, b) {
    const cat = leerL(LC, { plantillas: [], clases: [] }); const d = b.datos; d.tareas = d.tareas || []; d.unidad = d.unidad || {};
    const guardarLocal = () => { const l = leerL(LK, []).filter((x) => x.uid !== b.uid); b.guardado = new Date().toISOString(); l.unshift(b); return guardarL(LK, l); };
    const pintar = () => {
      const u = d.unidad; const F = (n, t, ph) => `<div class="fcampo"><label>${t}</label><input data-u="${n}" value="${h(u[n] || '')}" placeholder="${h(ph || '')}"></div>`;
      c.innerHTML = `<div class="tarjeta"><h2>${u.nombre || u.codigo ? h([u.codigo, u.nombre].filter(Boolean).join(' · ')) : 'Nuevo relevamiento'}</h2>
        ${b.nota ? `<div class="pmpsin">Para corregir: ${h(b.nota)}</div>` : ''}<p class="mini" id="rvEst">${navigator.onLine ? '' : 'Sin señal: se guarda en este dispositivo. '}${b.guardado ? 'Guardado en este dispositivo el ' + fecha(b.guardado) + '.' : ''}</p>
        <h3>Unidad</h3><div class="fgrid pmpfil">${F('codigo', 'Código (ID unidad)', 'Ej.: GAS-001-TAN-1')}${F('nombre', 'Nombre', 'Ej.: Tanque de oxígeno 1')}${F('sistema', 'Sistema', 'Ej.: Gases medicinales')}${F('ubicacion', 'Ubicación', 'Ej.: Edificio Central')}
          ${F('instalacion', 'Instalación')}${F('sector', 'Sector')}${F('categoria', 'Categoría')}
          <div class="fcampo"><label>Clase de sistema (PIM)</label><select data-u="clase"><option value="">— elegir —</option>${cat.clases.map(([k, v]) => `<option value="${h(k)}" ${u.clase === k ? 'selected' : ''}>${h(v)}</option>`).join('')}</select></div>
          ${F('responsable', 'Empresa responsable (código)')}</div>
        <h3>Tareas</h3><div class="fgrid pmpfil"><div class="fcampo"><label>Plantilla por tipo de equipo</label><select id="rvP"><option value="">— elegir —</option>${cat.plantillas.map((p, i) => `<option value="${i}">${h(p.nombre)} (${p.partes.length} tareas)</option>`).join('')}</select></div></div>
        <div class="acciones"><button class="b sec" id="rvPA">Agregar las tareas de la plantilla</button><button class="b sec" id="rvT">Agregar una tarea</button></div>
        <div class="pmpw rel"><table class="pmpg pmprel"><thead><tr><th>Parte o componente</th><th>Tarea preventiva</th><th>Predictivo</th><th>Personal</th><th>Frecuencia</th><th>Semana inicio</th><th>Insumos</th><th></th></tr></thead><tbody>
        ${d.tareas.map((t, i) => `<tr data-i="${i}"><td data-l="Parte o componente"><input data-k="parte" value="${h(t.parte || '')}"></td><td data-l="Tarea preventiva"><input data-k="preventivo" value="${h(t.preventivo || '')}"></td><td data-l="Predictivo"><input data-k="predictivo" value="${h(t.predictivo || '')}"></td>
          <td data-l="Personal"><input data-k="personal" value="${h(t.personal || '')}"></td><td data-l="Frecuencia"><select data-k="frecuencia"><option value="">—</option>${Object.entries(FRC).map(([k, v]) => `<option value="${k}" ${t.frecuencia === k ? 'selected' : ''}>${v[0]}</option>`).join('')}</select></td>
          <td data-l="Semana de inicio"><input data-k="semana_inicio" type="number" min="1" max="54" value="${h(t.semana_inicio || '')}" style="min-width:70px"></td><td data-l="Insumos"><input data-k="insumos" value="${h(t.insumos || '')}"></td><td><button class="b sec" data-q="${i}" title="Quitar esta tarea">✕ Quitar</button></td></tr>`).join('') || '<tr><td colspan="8" class="mini">Sin tareas: elegí una plantilla o agregá una tarea.</td></tr>'}</tbody></table></div>
        <div class="acciones"><button class="b sec" id="rvG">Guardar en este dispositivo</button><button class="b" id="rvE">Enviar a revisión</button><button class="b sec" id="rvV">Volver</button>${b.guardado ? '<button class="b sec" id="rvB">Descartar este borrador</button>' : ''}</div><div id="rvM"></div></div>`;
      c.querySelectorAll('[data-u]').forEach((x) => x.oninput = x.onchange = () => { d.unidad[x.dataset.u] = x.value; guardarLocal(); });
      c.querySelectorAll('tr[data-i] [data-k]').forEach((x) => x.oninput = x.onchange = () => { d.tareas[+x.closest('tr').dataset.i][x.dataset.k] = x.value; guardarLocal(); });
      c.querySelectorAll('[data-q]').forEach((x) => x.onclick = () => { d.tareas.splice(+x.dataset.q, 1); guardarLocal(); pintar(); });
      c.querySelector('#rvPA').onclick = () => { const p = cat.plantillas[+c.querySelector('#rvP').value]; if (!p) return; p.partes.forEach((x) => d.tareas.push({ parte: x.parte, preventivo: x.preventivo, frecuencia: x.frecuencia || '' })); guardarLocal(); pintar(); };
      c.querySelector('#rvT').onclick = () => { d.tareas.push({ parte: '', preventivo: '', frecuencia: '' }); guardarLocal(); pintar(); };
      c.querySelector('#rvG').onclick = () => aviso(c.querySelector('#rvM'), guardarLocal() ? 'ok' : 'mal', guardarLocal() ? 'Guardado en este dispositivo.' : 'No se pudo guardar en este dispositivo (memoria del navegador llena o bloqueada).');
      c.querySelector('#rvV').onclick = () => vistaRelevamiento(c);
      const bb = c.querySelector('#rvB'); if (bb) bb.onclick = () => { if (!confirm('¿Descartar este borrador? Se borra de este dispositivo.')) return; guardarL(LK, leerL(LK, []).filter((x) => x.uid !== b.uid)); vistaRelevamiento(c); };
      c.querySelector('#rvE').onclick = async () => { guardarLocal(); const m = c.querySelector('#rvM');
        try { await api('POST', 'm/preventivos/pmp/relevamientos', { uid: b.uid, datos: d }); guardarL(LK, leerL(LK, []).filter((x) => x.uid !== b.uid)); aviso(m, 'ok', 'Enviado a revisión.'); setTimeout(() => vistaRelevamiento(c), 900); }
        catch (e) { aviso(m, 'mal', (navigator.onLine ? e.message : 'Sin conexión') + '. Quedó guardado en este dispositivo: enviálo cuando vuelva la señal.'); } };
    };
    pintar();
  }
  async function revisar(c, id) {
    const r = (await api('GET', `m/preventivos/pmp/relevamientos/${id}`)).relevamiento; const u = r.datos.unidad;
    const p = panel(`Revisar · ${r.titulo}`, `<dl><dt>Relevó</dt><dd>${h(r.usuario)} · ${fecha(r.actualizado || r.creado)}</dd>${['codigo', 'nombre', 'sistema', 'ubicacion', 'instalacion', 'sector', 'categoria', 'clase', 'responsable'].filter((k) => u[k]).map((k) => `<dt>${({ codigo: 'Código', nombre: 'Nombre', sistema: 'Sistema', ubicacion: 'Ubicación', instalacion: 'Instalación', sector: 'Sector', categoria: 'Categoría', clase: 'Clase PIM', responsable: 'Responsable' })[k]}</dt><dd>${h(u[k])}</dd>`).join('')}</dl>
      ${tabla([{ t: 'Parte', v: (x) => x.parte || '' }, { t: 'Tarea', v: (x) => x.preventivo }, { t: 'Frecuencia', html: true, v: (x) => etq(x.frecuencia) }, { t: 'Semana inicio', v: (x) => x.semana_inicio || '' }, { t: 'Personal', v: (x) => x.personal || '' }], r.datos.tareas)}
      <p class="mini">Al aprobar: si el código no existe en PIM se crea el equipo (con la sigla del establecimiento) y las tareas pasan al plan; las que tienen semana de inicio quedan marcadas en el cronograma del año elegido.</p>
      <div class="fgrid pmpfil"><div class="fcampo"><label>Año del cronograma</label><input id="raA" type="number" value="${Y0}"></div><div class="fcampo"><label>Nota (obligatoria para devolver)</label><input id="raN" maxlength="300"></div></div>
      <div class="acciones"><button class="b" id="raOk">Aprobar y pasar al plan</button><button class="b sec" id="raNo">Devolver para corregir</button></div><div id="raM"></div>`);
    const fin = (txt) => { aviso(p.querySelector('#raM'), 'ok', txt); setTimeout(() => { Nucleo.cerrar(); vistaRelevamiento(c); }, 900); };
    p.querySelector('#raOk').onclick = async () => { try { const x = (await api('POST', `m/preventivos/pmp/relevamientos/${id}/aprobar`, { anio: +p.querySelector('#raA').value, nota: p.querySelector('#raN').value })).relevamiento; fin(`Aprobado: ${x.resultado.tareas.length} tareas en el plan${x.resultado.activo_creado ? '; equipo nuevo creado en PIM' : ''}.`); } catch (e) { aviso(p.querySelector('#raM'), 'mal', e.message); } };
    p.querySelector('#raNo').onclick = async () => { try { await api('POST', `m/preventivos/pmp/relevamientos/${id}/rechazar`, { nota: p.querySelector('#raN').value }); fin('Devuelto para corregir.'); } catch (e) { aviso(p.querySelector('#raM'), 'mal', e.message); } };
  }
})();
