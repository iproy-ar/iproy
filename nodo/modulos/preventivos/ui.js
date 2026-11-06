// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo preventivos · planes, calendario proyectado y órdenes generadas.
// 1.6.0: activo, frecuencia, tipo, punto de uso y responsable se eligen de listas; formulario plegable.
(() => {
  const { h, fecha, aviso, tabla, panel, form, api, pim, puede, seccion } = Nucleo;
  const TIPOS = [['preventivo-sistematico', 'Preventivo sistemático (por calendario)'], ['preventivo-condicional', 'Preventivo condicional (por uso o estado)'], ['ensayo-reglamentario', 'Ensayo reglamentario'], ['mejora', 'Mejora']];
  const opcionesActivo = async () => ((await pim('GET', 'Device?_count=500')).cuerpo.entry || []).map((x) => x.resource).filter((d) => d.status !== 'inactive')
    .map((d) => [d.id, `${d.deviceName?.[0]?.name || d.id} · ${d.identifier?.[0]?.value || ''}${d.location?.display ? ' · ' + d.location.display : ''}`]).sort((a, b) => a[1].localeCompare(b[1]));
  const organizaciones = async () => ((await pim('GET', 'Organization?_count=300')).cuerpo.entry || []).map((x) => x.resource)
    .map((o) => [o.identifier?.[0]?.value || o.id, `${o.name || o.id}${o.identifier?.[0]?.value ? ' · ' + o.identifier[0].value : ''}`]).sort((a, b) => a[1].localeCompare(b[1]));
  const puntos = async () => { try { const u = (await api('GET', 'm/telemetria/ultimos')).ultimos; return [...new Set(u.map((x) => x.punto))].sort().map((p) => [p, p]); } catch (x) { return []; } };
  Nucleo.pestana({ id: 'preventivos', titulo: 'Preventivos', orden: 30, cap: 'preventivos.ver', render: async (el) => {
    const [p, c] = await Promise.all([api('GET', 'm/preventivos/planes'), api('GET', 'm/preventivos/calendario?dias=90')]);
    const hoy = new Date().toISOString().slice(0, 10);
    el.innerHTML = `<div class="tarjeta"><h2>Planes</h2>${tabla([{ t: 'Plan', v: (x) => x.titulo }, { t: 'Activo', v: (x) => x.activo_nombre || x.activo_id },
        { t: 'Frecuencia', v: (x) => [x.cada_dias ? `cada ${x.cada_dias} días` : '', x.cada_valor ? `cada ${x.cada_valor} de ${x.punto}` : ''].filter(Boolean).join(' · ') },
        { t: 'Próxima', html: true, v: (x) => x.proxima ? `<span class="et ${x.proxima < hoy ? 'rojo' : x.proxima === hoy ? 'nar' : ''}">${h(x.proxima)}</span>` : '—' },
        { t: 'Última', v: (x) => x.ultima || '—' }, { t: 'Orden abierta', v: (x) => x.orden_abierta || '' }, { t: 'Hechas', v: (x) => x.hechas }], p.planes, detalle)}
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
  } });
  async function detalle(x) {
    const p = panel(x.titulo, `<dl><dt>Activo</dt><dd>${h(x.activo_nombre)}</dd><dt>Tipo</dt><dd>${h((TIPOS.find((t) => t[0] === x.tipo_mant) || [0, x.tipo_mant])[1])}</dd><dt>Próxima</dt><dd>${h(x.proxima || '—')}</dd><dt>Tareas</dt><dd style="white-space:pre-wrap">${h(x.tareas || '—')}</dd></dl>
      ${puede('preventivos.editar') ? '<div class="acciones"><button class="b" id="pGen">Generar la orden ahora</button></div>' : ''}<div id="pMsg"></div>`);
    const b = p.querySelector('#pGen');
    if (b) b.onclick = async () => { try { const r = await api('POST', `m/preventivos/planes/${x.id}/generar`); aviso(p.querySelector('#pMsg'), 'ok', 'Orden ' + r.orden + ' generada.'); } catch (e) { aviso(p.querySelector('#pMsg'), 'mal', e.message); } };
  }
})();
