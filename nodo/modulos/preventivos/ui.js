// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo preventivos · planes, calendario proyectado y órdenes generadas.
(() => {
  const { h, fecha, aviso, tabla, panel, form, api, puede } = Nucleo;
  Nucleo.pestana({ id: 'preventivos', titulo: 'Preventivos', orden: 30, cap: 'preventivos.ver', render: async (el) => {
    const [p, c] = await Promise.all([api('GET', 'm/preventivos/planes'), api('GET', 'm/preventivos/calendario?dias=90')]);
    const hoy = new Date().toISOString().slice(0, 10);
    el.innerHTML = `<div class="tarjeta"><h2>Planes</h2>${tabla([{ t: 'Plan', v: (x) => x.titulo }, { t: 'Activo', v: (x) => x.activo_nombre || x.activo_id },
        { t: 'Frecuencia', v: (x) => [x.cada_dias ? `cada ${x.cada_dias} días` : '', x.cada_valor ? `cada ${x.cada_valor} de ${x.punto}` : ''].filter(Boolean).join(' · ') },
        { t: 'Próxima', html: true, v: (x) => x.proxima ? `<span class="et ${x.proxima < hoy ? 'rojo' : x.proxima === hoy ? 'nar' : ''}">${h(x.proxima)}</span>` : '—' },
        { t: 'Última', v: (x) => x.ultima || '—' }, { t: 'Orden abierta', v: (x) => x.orden_abierta || '' }, { t: 'Hechas', v: (x) => x.hechas }], p.planes, detalle)}
      ${puede('preventivos.editar') ? `<h2 style="margin-top:16px">Nuevo plan</h2>${form([{ n: 'titulo', t: 'Título', req: true }, { n: 'activo_id', t: 'Activo (código de inventario o id PIM)', req: true },
        { n: 'cada_dias', t: 'Cada cuántos días (calendario)', tipo: 'number' }, { n: 'proxima', t: 'Primera fecha', tipo: 'date' }, { n: 'anticipacion_dias', t: 'Días de anticipación', tipo: 'number', v: 0 },
        { n: 'punto', t: 'Punto de uso (p. ej. HORAS-MARCHA)' }, { n: 'cada_valor', t: 'Cada cuánto uso', tipo: 'number' },
        { n: 'prioridad', t: 'Prioridad', tipo: 'select', op: [['routine', 'Rutina'], ['urgent', 'Urgente'], ['asap', 'Lo antes posible']] }, { n: 'responsable', t: 'Responsable (código de la organización)' },
        { n: 'tareas', t: 'Tareas (lista de verificación)', tipo: 'textarea' }], 'Crear plan', async (d, m) => {
          Object.keys(d).forEach((k) => (d[k] === '' || d[k] === null) && delete d[k]); await api('POST', 'm/preventivos/planes', d); aviso(m, 'ok', 'Plan creado.'); setTimeout(() => Nucleo.ir('preventivos'), 700); })}` : ''}</div>
      <div class="tarjeta"><h2>Próximos 90 días</h2>${tabla([{ t: 'Fecha', v: (x) => x.fecha }, { t: 'Plan', v: (x) => x.titulo }, { t: 'Activo', v: (x) => x.activo || '' }], c.calendario.slice(0, 60))}</div>`;
  } });
  async function detalle(x) {
    const p = panel(x.titulo, `<dl><dt>Activo</dt><dd>${h(x.activo_nombre)}</dd><dt>Tipo</dt><dd>${h(x.tipo_mant)}</dd><dt>Próxima</dt><dd>${h(x.proxima || '—')}</dd><dt>Tareas</dt><dd style="white-space:pre-wrap">${h(x.tareas || '—')}</dd></dl>
      ${puede('preventivos.editar') ? '<div class="acciones"><button class="b" id="pGen">Generar la orden ahora</button></div>' : ''}<div id="pMsg"></div>`);
    const b = p.querySelector('#pGen');
    if (b) b.onclick = async () => { try { const r = await api('POST', `m/preventivos/planes/${x.id}/generar`); aviso(p.querySelector('#pMsg'), 'ok', 'Orden ' + r.orden + ' generada.'); } catch (e) { aviso(p.querySelector('#pMsg'), 'mal', e.message); } };
  }
})();
