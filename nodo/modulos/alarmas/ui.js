// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo alarmas · lista de anuncio (ISA-18.2), definiciones, historial e indicadores.
(() => {
  const { h, fecha, aviso, tabla, panel, form, api, puede } = Nucleo;
  const P = { 1: ['rojo', '1 · crítica'], 2: ['nar', '2 · alta'], 3: ['ama', '3 · media'], 4: ['', '4 · baja'] };
  const E = { 'no-reconocida': 'rojo', reconocida: 'nar', 'rtn-no-reconocida': 'ama', enclavada: 'nar', normal: 'verde', archivada: 'gris', suprimida: 'gris', 'fuera-servicio': 'gris' };
  const pr = (p) => `<span class="et ${P[p][0]}">${P[p][1]}</span>`;
  const es = (e) => `<span class="et ${E[e] || ''}">${h(e)}</span>`;

  Nucleo.tablero(async (el) => {
    if (!puede('alarmas.ver')) return;
    const a = (await api('GET', 'm/alarmas/activas')).alarmas;
    el.innerHTML = `<div class="tarjeta"><h2>Alarmas activas (${a.length})</h2>${tabla([{ t: 'Prioridad', html: true, v: (x) => pr(x.prioridad) }, { t: 'Etiqueta', v: (x) => x.etiqueta },
      { t: 'Estado', html: true, v: (x) => es(x.estado) }, { t: 'Mensaje', v: (x) => x.mensaje }, { t: 'Valor', v: (x) => x.valor }, { t: 'Desde', v: (x) => fecha(x.activada) }], a, () => Nucleo.ir('alarmas'))}</div>`;
  });

  Nucleo.pestana({ id: 'alarmas', titulo: 'Alarmas', orden: 40, cap: 'alarmas.ver', render: async (el) => {
    const [a, d, k] = await Promise.all([api('GET', 'm/alarmas/activas'), api('GET', 'm/alarmas/definiciones'), api('GET', 'm/alarmas/kpi')]);
    const K = k.kpi;
    el.innerHTML = `<div class="tarjeta"><h2>Lista de anuncio</h2>${tabla([{ t: 'Prioridad', html: true, v: (x) => pr(x.prioridad) }, { t: 'Etiqueta', v: (x) => x.etiqueta }, { t: 'Estado', html: true, v: (x) => es(x.estado) },
        { t: 'Mensaje', v: (x) => x.mensaje }, { t: 'Valor / límite', v: (x) => `${x.valor ?? '—'} / ${x.tipo} ${x.limite}` }, { t: 'Activada', v: (x) => fecha(x.activada) }, { t: 'Orden', v: (x) => x.orden || '' }], a.alarmas, operar)}</div>
      <div class="tarjeta"><h2>Desempeño (ISA-18.2 · EEMUA 191)</h2><div class="kpis"><div class="kpi"><b>${K.activaciones_24h}</b><span>activaciones en 24 h</span></div>
        <div class="kpi"><b>${K.promedio_por_10min}</b><span>promedio cada 10 min</span></div><div class="kpi"><b style="color:${K.avalancha ? 'var(--mal)' : ''}">${K.pico_10min}</b><span>pico en 10 min${K.avalancha ? ' · AVALANCHA' : ''}</span></div>
        <div class="kpi"><b>${K.estancadas.length}</b><span>estancadas</span></div><div class="kpi"><b>${K.intermitentes.length}</b><span>intermitentes</span></div><div class="kpi"><b>${K.archivadas + K.suprimidas_o_fuera}</b><span>archivadas / suprimidas / fuera de servicio</span></div></div>
        <p class="mini">${h(K.objetivo)}</p>${K.peores_10.length ? '<h3>Las que más se activan (7 días)</h3>' + tabla([{ t: 'Etiqueta', v: (x) => x.etiqueta }, { t: 'Mensaje', v: (x) => x.mensaje }, { t: 'Activaciones', v: (x) => x.n }], K.peores_10) : ''}</div>
      <div class="tarjeta"><h2>Definiciones</h2>${tabla([{ t: 'Etiqueta', v: (x) => x.etiqueta }, { t: 'Activo · punto', v: (x) => x.activo_id + ' · ' + x.punto }, { t: 'Condición', v: (x) => `${x.tipo} ${x.limite} (banda ${x.banda}, retardo ${x.retardo_s} s)` },
        { t: 'Prioridad', html: true, v: (x) => pr(x.prioridad) }, { t: 'Estado', html: true, v: (x) => es(x.estado || 'normal') }, { t: 'Orden automática', v: (x) => +x.genera_orden ? 'sí' : 'no' }], d.alarmas, operar)}
      ${puede('alarmas.configurar') ? `<h2 style="margin-top:16px">Nueva alarma</h2>${form([{ n: 'etiqueta', t: 'Etiqueta (p. ej. PT-101-H)', req: true }, { n: 'activo_id', t: 'Id PIM del activo', req: true }, { n: 'punto', t: 'Punto (p. ej. PRES-LINEA)', req: true },
        { n: 'tipo', t: 'Tipo', tipo: 'select', op: [['alta', 'alta'], ['alta-alta', 'alta-alta'], ['baja', 'baja'], ['baja-baja', 'baja-baja']] }, { n: 'limite', t: 'Límite', tipo: 'number', req: true }, { n: 'banda', t: 'Banda muerta', tipo: 'number', v: 0 },
        { n: 'retardo_s', t: 'Retardo de activación (s)', tipo: 'number', v: 0 }, { n: 'prioridad', t: 'Prioridad', tipo: 'select', op: [[1, '1 · crítica'], [2, '2 · alta'], [3, '3 · media'], [4, '4 · baja']], v: 3 },
        { n: 'genera_orden', t: 'Generar orden en PIM al activarse', tipo: 'check' }, { n: 'enclavar', t: 'Enclavada (requiere restablecer)', tipo: 'check' },
        { n: 'mensaje', t: 'Mensaje para el operador', tipo: 'textarea', req: true }, { n: 'accion', t: 'Acción esperada (respuesta del operador)', tipo: 'textarea' }],
        'Crear alarma', async (x, m) => { await api('POST', 'm/alarmas/definiciones', x); aviso(m, 'ok', 'Alarma creada.'); setTimeout(() => Nucleo.ir('alarmas'), 700); })}` : ''}</div>`;
  } });
  async function operar(x) {
    const hs = (await api('GET', 'm/alarmas/historial?id=' + x.id)).historial;
    const b = (a, t, c = 'sec') => `<button class="b ${c}" data-a="${a}">${t}</button>`;
    const p = panel('Alarma ' + x.etiqueta, `<dl><dt>Estado</dt><dd>${es(x.estado || 'normal')}</dd><dt>Mensaje</dt><dd>${h(x.mensaje)}</dd><dt>Acción esperada</dt><dd>${h(x.accion || '—')}</dd>
      <dt>Condición</dt><dd>${h(x.tipo)} ${h(x.limite)} · banda ${h(x.banda)} · retardo ${h(x.retardo_s)} s</dd><dt>Valor</dt><dd>${h(x.valor ?? '—')}</dd><dt>Motivo</dt><dd>${h(x.motivo || '—')}</dd></dl>
      ${puede('alarmas.operar') ? `<label style="margin-top:12px">Nota o motivo</label><input id="aNota"><label>Minutos (archivar)</label><input id="aMin" type="number" value="60">
      <div class="acciones">${b('reconocer', 'Reconocer', '')}${b('archivar', 'Archivar')}${b('restablecer', 'Restablecer')}${b('restaurar', 'Restaurar')}${puede('alarmas.configurar') ? b('suprimir', 'Suprimir', 'peligro') + b('fuera-servicio', 'Fuera de servicio', 'peligro') : ''}</div>` : ''}
      <div id="aMsg"></div><h3>Historial</h3>${tabla([{ t: 'Fecha', v: (y) => fecha(y.ts) }, { t: 'Evento', v: (y) => y.evento }, { t: 'De → a', v: (y) => `${y.de || ''} → ${y.a}` }, { t: 'Valor', v: (y) => y.valor ?? '' }, { t: 'Usuario', v: (y) => y.usuario || '' }, { t: 'Nota', v: (y) => y.nota || '' }], hs)}`);
    p.querySelectorAll('button[data-a]').forEach((bt) => bt.onclick = async () => {
      try { const r = await api('POST', `m/alarmas/${x.id}/${bt.dataset.a}`, { nota: p.querySelector('#aNota').value, motivo: p.querySelector('#aNota').value, minutos: +p.querySelector('#aMin').value });
        aviso(p.querySelector('#aMsg'), 'ok', 'Estado: ' + r.alarma.estado); setTimeout(() => { Nucleo.cerrar(); Nucleo.ir('alarmas'); }, 700);
      } catch (e) { aviso(p.querySelector('#aMsg'), 'mal', e.message); }
    });
  }
})();
