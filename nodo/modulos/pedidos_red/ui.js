// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo pedidos_red · 1.0.0: pedir un trabajo a otro nodo de la colmena y seguir su estado.
(() => {
  const { h, fecha, aviso, tabla, api, puede, kpis } = Nucleo;
  const EST = { enviando: ['enviando…', 'gris'], 'en-cola': ['esperando camino', 'nar'], 'por-hub': ['en camino por el hub', 'nar'], error: ['no salió', 'rojo'], rechazado: ['rechazado por el otro nodo', 'rojo'],
    solicitada: ['solicitada', 'ama'], 'en-revision': ['en revisión', 'ama'], asignada: ['asignada', 'azul'], 'en-ejecucion': ['en ejecución', 'azul'], 'en-espera': ['en espera', 'nar'],
    finalizada: ['finalizada', 'verde'], cerrada: ['cerrada', 'verde'], rechazada: ['rechazada', 'rojo'], anulada: ['anulada', 'gris'] };
  const et = (e) => { const [t, c] = EST[e] || [e, 'gris']; return `<span class="et ${c}">${h(t)}</span>`; };
  const S = { par: '', elegido: null, res: [] };

  Nucleo.pestana({ id: 'pedidos_red', titulo: 'Pedidos a otros nodos', orden: 82, cap: 'pedidos_red.ver', grupo: 'red', icono: 'colmena',
    desc: 'Pedir un trabajo a otra institución de la colmena y seguir su orden', render: async (el) => {
      const [n, l] = await Promise.all([api('GET', 'm/pedidos_red/nodos'), api('GET', 'm/pedidos_red/pedidos')]);
      const ped = puede('pedidos_red.pedir'); const P = l.pedidos;
      if (!S.par && n.nodos.length) S.par = n.nodos[0].par;
      const abiertos = P.filter((p) => !['finalizada', 'cerrada', 'rechazada', 'anulada', 'rechazado', 'error'].includes(p.estado)).length;
      el.innerHTML = `${ped ? `<div class="tarjeta"><h2>Pedir un trabajo a otro nodo</h2>
        ${n.nodos.length ? `<p class="mini">El pedido se crea en el Servidor PIM del otro nodo, que le da su número de orden y lo asigna. Para poder pedir, el otro nodo tiene que haberle dado a este el rol <b>SO</b> (solicitante) en Colmena.</p>
          <div class="fgrid prfil"><div class="fcampo"><label>Nodo</label><select id="prN">${n.nodos.map((x) => `<option value="${h(x.par)}" ${x.par === S.par ? 'selected' : ''}>${h(x.nombre || x.par)} · ${h(x.par)}</option>`).join('')}</select></div>
            <div class="fcampo"><label>Equipo o instalación (nombre o código)</label><input id="prQ" placeholder="Ej.: autoclave, GAS-001"></div></div>
          <div class="acciones"><button class="b sec" id="prB">Buscar en ese nodo</button></div><div id="prR"></div>
          <div id="prF" hidden><div class="fgrid prfil"><div class="fcampo" style="flex-basis:100%"><label>Trabajo a pedir</label><textarea id="prD" maxlength="2000" placeholder="Qué hay que hacer, dónde y cualquier dato útil"></textarea></div>
            <div class="fcampo"><label>Prioridad</label><select id="prP">${Object.entries(n.prioridades).map(([k, v]) => `<option value="${k}">${h(v)}</option>`).join('')}</select></div></div>
            <div class="acciones"><button class="b" id="prE">Enviar el pedido</button></div></div><div id="prM"></div>`
          : '<p class="mini">Este nodo no tiene vínculos activos en la colmena. Se arman en Colmena › «Pedir un vínculo».</p>'}</div>` : ''}
        <div class="tarjeta"><h2>Pedidos hechos <small class="mini">· ${P.length}</small></h2>
          ${kpis([{ t: 'Abiertos', n: abiertos, icono: 'reloj', color: '#1e88e5' }, { t: 'Finalizados', n: P.filter((p) => ['finalizada', 'cerrada'].includes(p.estado)).length, icono: 'ok', color: '#2e7d32' },
            { t: 'Con problema', n: P.filter((p) => ['rechazado', 'error', 'rechazada'].includes(p.estado)).length, icono: 'alarmas', color: '#c62828' }])}
          <div class="acciones"><button class="b sec" id="prA">Actualizar los estados</button></div><div id="prAM"></div>
          ${tabla([{ t: 'Orden', html: true, v: (p) => p.numero ? `<b>${h(p.numero)}</b>` : '<span class="mini">sin número todavía</span>' }, { t: 'Nodo', v: (p) => p.par_nombre || p.par },
            { t: 'Equipo o lugar', v: (p) => [p.activo_nombre, p.activo_codigo].filter(Boolean).join(' · ') || p.activo_ref }, { t: 'Pedido', v: (p) => p.detalle.length > 90 ? p.detalle.slice(0, 90) + '…' : p.detalle },
            { t: 'Estado', html: true, v: (p) => et(p.estado) + (p.motivo ? `<br><small class="mini">${h(p.motivo)}</small>` : '') + (p.error ? `<br><small class="mini">${h(p.error)}</small>` : '') },
            { t: 'Responsable', v: (p) => p.responsable || '' }, { t: 'Pedido el', v: (p) => `${fecha(p.creado)} · ${p.creado_por}` }, { t: 'Consultado', v: (p) => p.consultado ? fecha(p.consultado) : '' }], P,
            async (p) => { const m = el.querySelector('#prAM'); try { aviso(m, 'info', `Consultando la orden en ${p.par_nombre || p.par}…`); const x = (await api('POST', `m/pedidos_red/pedidos/${p.id}/actualizar`)).pedido; aviso(m, 'ok', `${x.numero || 'Sin número'}: ${(EST[x.estado] || [x.estado])[0]}.`); setTimeout(() => Nucleo.ir('pedidos_red'), 900); } catch (e) { aviso(m, 'mal', e.message); } })}
          <p class="mini">Tocá un pedido para consultar su estado ahora. El nodo los consulta solo cada 10 minutos mientras estén abiertos.</p></div>`;
      if (!document.getElementById('pr-css')) { const st = document.createElement('style'); st.id = 'pr-css'; st.textContent = '.prfil{display:flex;flex-wrap:wrap;gap:4px 12px}.prfil .fcampo{flex:1 1 220px;min-width:0}.prfil .fcampo input,.prfil .fcampo select,.prfil .fcampo textarea{width:100%}.prfil textarea{min-height:90px}.prres .clic{cursor:pointer}'; document.head.appendChild(st); }
      el.querySelector('#prA').onclick = async () => { const m = el.querySelector('#prAM'); aviso(m, 'info', 'Consultando…'); let ok = 0;
        for (const p of P.filter((x) => !['finalizada', 'cerrada', 'rechazada', 'anulada', 'rechazado', 'error'].includes(x.estado))) { try { await api('POST', `m/pedidos_red/pedidos/${p.id}/actualizar`); ok++; } catch (x) { /* sigue con los demás */ } }
        aviso(m, 'ok', `${ok} pedidos consultados.`); setTimeout(() => Nucleo.ir('pedidos_red'), 700); };
      if (!ped || !n.nodos.length) return;
      const q = el.querySelector('#prQ'); el.querySelector('#prN').onchange = (e) => { S.par = e.target.value; S.elegido = null; el.querySelector('#prR').innerHTML = ''; el.querySelector('#prF').hidden = true; };
      const buscar = async () => { const r = el.querySelector('#prR'); S.elegido = null; el.querySelector('#prF').hidden = true; aviso(r, 'info', 'Buscando en el otro nodo…');
        try { S.res = (await api('GET', `m/pedidos_red/buscar?par=${encodeURIComponent(S.par)}&q=${encodeURIComponent(q.value.trim())}`)).resultados;
          r.innerHTML = S.res.length ? `<div class="prres">${tabla([{ t: 'Nombre', v: (x) => x.nombre }, { t: 'Código', v: (x) => x.codigo || '' }, { t: 'Tipo', v: (x) => x.tipo }, { t: 'Lugar', v: (x) => x.lugar || '' }], S.res, (x) => {
            S.elegido = x; r.innerHTML = `<div class="aviso ok">Elegido: <b>${h(x.nombre)}</b>${x.codigo ? ' · ' + h(x.codigo) : ''} <button class="b sec" id="prX" style="margin-left:8px">Cambiar</button></div>`;
            el.querySelector('#prX').onclick = buscar; el.querySelector('#prF').hidden = false; el.querySelector('#prD').focus(); })}</div><p class="mini">Tocá el que corresponde.</p>` : '<p class="mini">No se encontró nada con ese nombre o código en ese nodo.</p>'; }
        catch (e) { aviso(r, 'mal', e.message); } };
      el.querySelector('#prB').onclick = buscar; q.onkeydown = (e) => { if (e.key === 'Enter') buscar(); };
      el.querySelector('#prE').onclick = async () => { const m = el.querySelector('#prM'); const b = el.querySelector('#prE'); if (!S.elegido) return aviso(m, 'mal', 'Elegí primero el equipo o la instalación.');
        b.disabled = true; aviso(m, 'info', 'Enviando al otro nodo…');
        try { const x = (await api('POST', 'm/pedidos_red/pedidos', { par: S.par, activo: S.elegido.ref, activo_nombre: S.elegido.nombre, activo_codigo: S.elegido.codigo, detalle: el.querySelector('#prD').value, prioridad: el.querySelector('#prP').value })).pedido;
          aviso(m, x.estado === 'rechazado' || x.estado === 'error' ? 'mal' : 'ok', x.numero ? `Pedido creado en ${x.par_nombre || x.par}: orden ${x.numero} (${(EST[x.estado] || [x.estado])[0]}).` : x.error || `Pedido ${(EST[x.estado] || [x.estado])[0]}: el número llega cuando el otro nodo responda.`);
          if (x.estado !== 'rechazado' && x.estado !== 'error') setTimeout(() => Nucleo.ir('pedidos_red'), 1600); else b.disabled = false; }
        catch (e) { aviso(m, 'mal', e.message); b.disabled = false; } };
    } });
})();
