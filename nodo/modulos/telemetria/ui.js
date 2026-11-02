// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo telemetría · últimos valores y serie de 24 h (gráfico SVG sin librerías).
(() => {
  const { h, fecha, tabla, panel, api } = Nucleo;
  Nucleo.pestana({ id: 'telemetria', titulo: 'Telemetría', orden: 50, cap: 'telemetria.ver', render: async (el) => {
    const u = (await api('GET', 'm/telemetria/ultimos')).ultimos;
    el.innerHTML = `<div class="tarjeta"><h2>Últimos valores</h2>${tabla([{ t: 'Activo', v: (x) => (x.codigo ? x.codigo + ' · ' : '') + x.nombre }, { t: 'Punto', v: (x) => x.punto },
      { t: 'Valor', v: (x) => `${x.valor} ${x.unidad}` }, { t: 'Cuándo', v: (x) => fecha(x.ts) }, { t: 'Origen', v: (x) => x.origen || '' }, { t: 'Lecturas', v: (x) => x.n }], u, serie)}
      <p class="mini">Ingreso: MQTT (pim/v1/{refes}/{activo}/{punto}), Modbus TCP, HTTP POST /api/m/telemetria/medicion o eventos de otros nodos. A PIM se envían por excepción (banda muerta) y cada 5 min como mínimo.</p></div>`;
  } });
  async function serie(x) {
    const s = (await api('GET', `m/telemetria/serie?activo=${encodeURIComponent(x.activo_id)}&punto=${encodeURIComponent(x.punto)}&horas=24`)).serie;
    const p = panel(`${x.nombre} · ${x.punto}`, '<div id="tG"></div>');
    if (s.length < 2) { p.querySelector('#tG').innerHTML = '<p class="mini">Hace falta más de una lectura para graficar.</p>'; return; }
    const W = 580, H = 220, v = s.map((y) => +y.valor), t = s.map((y) => new Date(y.ts.replace(' ', 'T')).getTime());
    const [a, b] = [Math.min(...v), Math.max(...v)], [t0, t1] = [t[0], t[t.length - 1]];
    const X = (i) => 40 + (W - 50) * (t1 === t0 ? 0 : (t[i] - t0) / (t1 - t0)), Y = (y) => H - 25 - (H - 40) * (b === a ? 0.5 : (y - a) / (b - a));
    p.querySelector('#tG').innerHTML = `<svg viewBox="0 0 ${W} ${H}" style="width:100%;background:#fff;border:1px solid var(--borde);border-radius:10px">
      <polyline fill="none" stroke="var(--az3)" stroke-width="2" points="${v.map((y, i) => X(i).toFixed(1) + ',' + Y(y).toFixed(1)).join(' ')}"/>
      <text x="4" y="${Y(b) + 4}" font-size="11" fill="#5b7089">${h(b)}</text><text x="4" y="${Y(a) + 4}" font-size="11" fill="#5b7089">${h(a)}</text>
      <text x="40" y="${H - 6}" font-size="11" fill="#5b7089">${h(fecha(s[0].ts))}</text><text x="${W - 120}" y="${H - 6}" font-size="11" fill="#5b7089">${h(fecha(s[s.length - 1].ts))}</text></svg>
      <p class="mini">${s.length} lecturas · ${h(x.unidad)}</p>`;
  }
})();
