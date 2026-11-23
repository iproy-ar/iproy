// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo notificaciones · pestaña «Avisos»: reglas (de qué, a quién, por dónde), historial de envíos y canales.
(() => {
  const { h, fecha, aviso, tabla, panel, api, puede, kpis, alClicKpi, csv, icono, seccion } = Nucleo;
  const EST = { pendiente: ['ama', 'En cola'], enviado: ['verde', 'Enviado'], fallido: ['rojo', 'Con falla'], descartado: ['gris', 'Silenciado'] };
  const CAN = { correo: 'Correo', telegram: 'Telegram', webhook: 'Webhook' };
  const et = (e) => `<span class="et ${EST[e]?.[0] || ''}">${h(EST[e]?.[1] || e)}</span>`;
  const movil = () => innerWidth < 700;
  let vista = 'reglas', filtro = { estado: '', canal: '', desde: '', hasta: '', q: '' };

  Nucleo.tablero(async (el) => {
    if (!puede('notificaciones.ver')) { el.remove(); return; }
    const r = (await api('GET', 'm/notificaciones/resumen')).resumen;
    el.innerHTML = `<div class="tarjeta"><h2>Avisos</h2>${kpis([{ t: 'Reglas activas', n: r.reglas_activas, sub: `de ${r.reglas}`, icono: 'avisos', color: '#8e24aa', k: 'reglas' },
      { t: 'Enviados', n: r.enviados_24h, sub: 'últimas 24 h', icono: 'ok', color: '#43a047', k: 'enviado' }, { t: 'En cola', n: r.pendientes, sub: 'esperando salir', icono: 'reloj', color: '#fb8c00', k: 'pendiente' },
      { t: 'Con falla', n: r.fallidos_7d, sub: 'últimos 7 días', icono: 'alarmas', color: '#e53935', k: 'fallido' }])}</div>`;
    alClicKpi(el, (k) => { vista = k === 'reglas' ? 'reglas' : 'historial'; if (k !== 'reglas') filtro = { estado: k, canal: '', desde: '', hasta: '', q: '' }; Nucleo.ir('avisos'); });
  }, { id: 'notificaciones.resumen', titulo: 'Avisos' });

  Nucleo.pestana({ id: 'avisos', titulo: 'Avisos', orden: 45, cap: 'notificaciones.ver', render: async (el) => {
    const [res, fu] = await Promise.all([api('GET', 'm/notificaciones/resumen'), api('GET', 'm/notificaciones/fuentes')]);
    const R = res.resumen, F = fu.fuentes.filter((f) => f.disponible);
    el.innerHTML = `<div id="nK"></div><div class="subp" id="nSub" style="margin-top:12px"></div><div id="nC"></div>`;
    const pintarK = () => { el.querySelector('#nK').innerHTML = kpis([
      { t: 'Reglas activas', n: R.reglas_activas, sub: `de ${R.reglas} cargadas`, icono: 'avisos', color: '#8e24aa', k: 'reglas', activo: vista === 'reglas' },
      { t: 'Enviados', n: R.enviados_24h, sub: 'últimas 24 h', icono: 'ok', color: '#43a047', k: 'enviado', activo: vista === 'historial' && filtro.estado === 'enviado' },
      { t: 'En cola', n: R.pendientes, sub: 'esperando salir', icono: 'reloj', color: '#fb8c00', k: 'pendiente', activo: vista === 'historial' && filtro.estado === 'pendiente' },
      { t: 'Con falla', n: R.fallidos_7d, sub: 'últimos 7 días', icono: 'alarmas', color: '#e53935', k: 'fallido', activo: vista === 'historial' && filtro.estado === 'fallido' },
      { t: 'Silenciados', n: R.silenciados_7d, sub: 'repetidos, 7 días', icono: 'candado', color: '#546e7a', k: 'descartado', activo: vista === 'historial' && filtro.estado === 'descartado' }]);
      alClicKpi(el.querySelector('#nK'), (k) => { if (k === 'reglas') vista = 'reglas'; else { vista = 'historial'; filtro = { ...filtro, estado: k }; } pintar(); }); };
    const pintar = () => {
      pintarK();
      el.querySelector('#nSub').innerHTML = [['reglas', 'Reglas', R.reglas], ['historial', 'Historial', null], ['canales', 'Canales', null]].map(([v, t, n]) => `<button data-v="${v}" class="${vista === v ? 'activo' : ''}">${t}${n != null ? ` <em>${n}</em>` : ''}</button>`).join('');
      el.querySelectorAll('#nSub button').forEach((b) => b.onclick = () => { vista = b.dataset.v; pintar(); });
      ({ reglas: verReglas, historial: verHistorial, canales: verCanales })[vista](el.querySelector('#nC'));
    };

    // ------------------------------------------------------------------ reglas
    async function verReglas(c) {
      const reglas = (await api('GET', 'm/notificaciones/reglas')).reglas;
      const cfg = puede('notificaciones.configurar');
      c.innerHTML = `<div class="tarjeta"><h2>Reglas de aviso</h2>${reglas.length ? tabla([{ t: 'Regla', v: (x) => x.nombre }, { t: 'Avisa cuando', v: (x) => x.cuando }, { t: 'Por', v: (x) => x.canal_nombre }, { t: 'A quién', v: (x) => x.destino },
        { t: 'No repetir', v: (x) => x.silencio_min ? x.silencio_min + ' min' : '—' }, { t: 'Estado', html: true, v: (x) => `<span class="et ${x.activa ? 'verde' : 'gris'}">${x.activa ? 'Activa' : 'Pausada'}</span>` }], reglas, cfg ? (x) => editar(x) : null)
        : `<p class="mini">Todavía no hay reglas. ${cfg ? 'Creá la primera acá abajo: elegís de qué querés enterarte, por dónde y a quién le llega.' : ''}</p>`}
        ${cfg ? seccion('Nueva regla', `<div id="nNueva"></div>`, !reglas.length) : ''}</div>`;
      if (cfg) formRegla(c.querySelector('#nNueva'), null);
    }
    function formRegla(caja, r) {
      const tema0 = r?.tema || F[0]?.tema || '';
      caja.innerHTML = `<form class="form"><div class="fgrid">
        <div class="fcampo"><label>Nombre <b class="req">*</b></label><input name="nombre" required maxlength="80" value="${h(r?.nombre || '')}" placeholder="Ej.: Alarmas críticas a guardia"></div>
        <div class="fcampo"><label>Avisar cuando hay <b class="req">*</b></label><select name="tema">${F.map((f) => `<option value="${h(f.tema)}" ${f.tema === tema0 ? 'selected' : ''}>${h(f.nombre)}</option>`).join('')}</select><small class="fayuda" id="nAyT"></small></div>
        <div class="fcampo" data-todo="1" id="nCond"></div>
        <div class="fcampo"><label>Solo si contiene</label><input name="texto" maxlength="80" value="${h(r?.condicion?.texto || '')}" placeholder="Opcional: una palabra o etiqueta"></div>
        <div class="fcampo"><label>Por dónde <b class="req">*</b></label><select name="canal">${Object.entries(CAN).map(([v, t]) => `<option value="${v}" ${v === (r?.canal || 'correo') ? 'selected' : ''}>${t}${R.canales[v].listo ? '' : ' (falta configurar)'}</option>`).join('')}</select></div>
        <div class="fcampo" data-todo="1"><label>A quién <b class="req">*</b></label><textarea name="destino" required rows="2">${h(r?.destino || '')}</textarea><small class="fayuda" id="nAyD"></small></div>
        <div class="fcampo"><label>No repetir el mismo aviso durante</label><div class="fnum"><input name="silencio_min" inputmode="numeric" value="${h(r?.silencio_min ?? 0)}"><span>min</span></div><small class="fayuda">0 = avisar siempre.</small></div>
        <div class="fcampo"><label>Estado</label><label class="fchk"><input type="checkbox" name="activa" ${!r || r.activa ? 'checked' : ''}> Activa</label></div></div>
        <div class="acciones"><button class="b" type="submit">${r ? 'Guardar cambios' : 'Crear regla'}</button>${r ? '<button class="b sec" type="button" data-a="probar">Enviar una prueba</button><button class="b peligro" type="button" data-a="borrar">Borrar</button>' : ''}</div><div class="fmsg"></div></form>`;
      const f = caja.querySelector('form'), msg = caja.querySelector('.fmsg');
      f.querySelectorAll('.fcampo').forEach((x) => { x.style.gridColumn = x.dataset.todo ? 'span 12' : `span ${movil() ? 12 : 6}`; });
      const cond = () => { const fu = F.find((x) => x.tema === f.tema.value); f.querySelector('#nAyT').textContent = fu?.ayuda || '';
        f.querySelector('#nCond').innerHTML = Object.entries(fu?.campos || {}).map(([k, c]) => { const sel = (r && r.tema === fu.tema ? r.condicion?.[k] : c.defecto) || [];
          return `<label>${h(c.t)} <span class="mini" style="font-weight:400">(sin marcar = cualquiera)</span></label><div class="chips" data-k="${h(k)}" style="margin-bottom:8px">${Object.entries(c.op).map(([v, t]) => `<label><input type="checkbox" value="${h(v)}" ${sel.map(String).includes(v) ? 'checked' : ''}> ${h(t)}</label>`).join('')}</div>`; }).join(''); };
      const ayD = () => { f.querySelector('#nAyD').textContent = { correo: 'Uno o más correos, separados por coma.', telegram: 'Número de chat o @canal, separados por coma. El bot tiene que estar en ese chat (mirá «Canales»).', webhook: 'Dirección http(s) que recibe el aviso en JSON. Sirve para WhatsApp u otro sistema a través de un servicio intermedio.' }[f.canal.value]; };
      f.tema.onchange = cond; f.canal.onchange = ayD; cond(); ayD();
      const datos = () => { const condicion = {}; f.querySelectorAll('#nCond .chips').forEach((g) => { const v = [...g.querySelectorAll('input:checked')].map((i) => i.value); if (v.length) condicion[g.dataset.k] = v; });
        if (f.texto.value.trim()) condicion.texto = f.texto.value.trim();
        return { nombre: f.nombre.value, tema: f.tema.value, condicion, canal: f.canal.value, destino: f.destino.value, silencio_min: parseInt(f.silencio_min.value || '0', 10) || 0, activa: f.activa.checked }; };
      f.onsubmit = async (e) => { e.preventDefault();
        try { if (r) await api('PUT', 'm/notificaciones/reglas/' + r.id, datos()); else await api('POST', 'm/notificaciones/reglas', datos());
          aviso(msg, 'ok', r ? 'Regla guardada.' : 'Regla creada.'); setTimeout(() => { Nucleo.cerrar(); Nucleo.ir('avisos'); }, 600);
        } catch (x) { aviso(msg, 'mal', x.message); } };
      f.querySelectorAll('button[data-a]').forEach((b) => b.onclick = async () => {
        try {
          if (b.dataset.a === 'probar') { b.disabled = true; aviso(msg, 'info', 'Enviando la prueba…'); const j = await api('POST', `m/notificaciones/reglas/${r.id}/probar`, {});
            msg.innerHTML = j.envios.map((x) => `<div class="aviso ${x.estado === 'enviado' ? 'ok' : 'mal'}">${h(x.destino)}: ${x.estado === 'enviado' ? 'llegó la prueba' : h(x.error || 'no salió')}</div>`).join(''); b.disabled = false; }
          else if (b.dataset.borrar) { await api('POST', `m/notificaciones/reglas/${r.id}/borrar`, {}); Nucleo.cerrar(); Nucleo.ir('avisos'); }
          else { b.dataset.borrar = '1'; b.textContent = 'Tocá de nuevo para borrar'; }
        } catch (x) { b.disabled = false; aviso(msg, 'mal', x.message); } });
    }
    function editar(r) { const p = panel('Regla: ' + r.nombre, `<p class="mini">${h(r.cuando)} → ${h(r.canal_nombre)}${r.modificado ? ` · modificada ${fecha(r.modificado)}${r.modificado_por ? ' por ' + h(r.modificado_por) : ''}` : ''}</p><div id="nEd"></div>`); formRegla(p.querySelector('#nEd'), r); }

    // ------------------------------------------------------------------ historial
    async function verHistorial(c) {
      const hoy = new Date().toISOString().slice(0, 10);
      const filtros = `<div class="filtros"><div style="min-width:150px"><label>Estado</label><select id="hE"><option value="">Todos</option>${Object.entries(EST).map(([v, t]) => `<option value="${v}" ${filtro.estado === v ? 'selected' : ''}>${t[1]}</option>`).join('')}</select></div>
        <div style="min-width:140px"><label>Canal</label><select id="hC"><option value="">Todos</option>${Object.entries(CAN).map(([v, t]) => `<option value="${v}" ${filtro.canal === v ? 'selected' : ''}>${t}</option>`).join('')}</select></div>
        <div><label>Desde</label><input type="date" id="hD" value="${h(filtro.desde)}" max="${hoy}"></div><div><label>Hasta</label><input type="date" id="hH" value="${h(filtro.hasta)}" max="${hoy}"></div>
        <div style="flex:2 1 180px"><label>Buscar</label><input type="search" id="hQ" value="${h(filtro.q)}" placeholder="Asunto o destinatario"></div>
        <button class="b" id="hAp">Aplicar</button><button class="b sec" id="hLi">Limpiar</button></div>`;
      const n = Object.values(filtro).filter(Boolean).length;
      c.innerHTML = `<div class="tarjeta"><h2>Historial de avisos</h2>${movil() ? seccion(`Filtros${n ? ` (${n} aplicados)` : ''}`, filtros, false) : filtros}<div class="herr"><span class="cuenta" id="hN"></span><span class="sep"></span><button class="b sec" id="hCsv">${icono('bajar')} Descargar CSV</button></div><div id="hT"><p class="mini">Cargando…</p></div></div>`;
      const g = (i) => c.querySelector(i);
      g('#hAp').onclick = () => { filtro = { estado: g('#hE').value, canal: g('#hC').value, desde: g('#hD').value, hasta: g('#hH').value, q: g('#hQ').value.trim() }; pintar(); };
      g('#hLi').onclick = () => { filtro = { estado: '', canal: '', desde: '', hasta: '', q: '' }; pintar(); };
      g('#hQ').onkeydown = (e) => { if (e.key === 'Enter') g('#hAp').click(); };
      const j = await api('GET', 'm/notificaciones/envios?' + new URLSearchParams(Object.fromEntries(Object.entries(filtro).filter(([, v]) => v))));
      g('#hN').textContent = j.total > j.envios.length ? `Se muestran los ${j.envios.length} más nuevos de ${j.total}` : `${j.total} aviso${j.total === 1 ? '' : 's'}`;
      g('#hT').innerHTML = j.envios.length ? tabla([{ t: 'Fecha', v: (x) => fecha(x.creado) }, { t: 'Aviso', v: (x) => x.asunto }, { t: 'Regla', v: (x) => x.regla }, { t: 'Por', v: (x) => CAN[x.canal] || x.canal }, { t: 'A quién', v: (x) => x.destino },
        { t: 'Estado', html: true, v: (x) => et(x.estado) }, { t: 'Detalle', v: (x) => x.estado === 'enviado' ? 'Salió ' + fecha(x.enviado) : (x.error || (x.estado === 'pendiente' ? 'Sale en el próximo paso' : '')) }], j.envios, detalle)
        : '<p class="mini">No hay avisos con esos filtros.</p>';
      g('#hCsv').onclick = () => csv('avisos_' + hoy, [{ t: 'Fecha', v: (x) => x.creado }, { t: 'Aviso', v: (x) => x.asunto }, { t: 'Regla', v: (x) => x.regla }, { t: 'Canal', v: (x) => CAN[x.canal] || x.canal }, { t: 'Destinatario', v: (x) => x.destino },
        { t: 'Estado', v: (x) => EST[x.estado]?.[1] || x.estado }, { t: 'Intentos', v: (x) => x.intentos }, { t: 'Enviado', v: (x) => x.enviado || '' }, { t: 'Motivo', v: (x) => x.error || '' }], j.envios);
    }
    function detalle(x) {
      const p = panel(x.asunto, `<dl><dt>Estado</dt><dd>${et(x.estado)}</dd><dt>Regla</dt><dd>${h(x.regla)}</dd><dt>Canal</dt><dd>${h(CAN[x.canal] || x.canal)} → ${h(x.destino)}</dd><dt>Creado</dt><dd>${fecha(x.creado)}</dd>
        ${x.enviado ? `<dt>Enviado</dt><dd>${fecha(x.enviado)}</dd>` : ''}<dt>Intentos</dt><dd>${x.intentos}</dd>${x.error ? `<dt>Motivo</dt><dd>${h(x.error)}</dd>` : ''}${x.proximo ? `<dt>Próximo intento</dt><dd>${fecha(x.proximo)}</dd>` : ''}</dl>
        <h3>Lo que dice el aviso</h3><dl>${x.renglones.map(([k, v]) => `<dt>${h(k)}</dt><dd>${h(v)}</dd>`).join('')}</dl>
        ${x.estado !== 'enviado' && puede('notificaciones.configurar') ? '<div class="acciones"><button class="b" id="hRe">Enviar ahora</button></div>' : ''}<div id="hMsg"></div>`);
      const b = p.querySelector('#hRe'); if (b) b.onclick = async () => { b.disabled = true;
        try { const r = (await api('POST', `m/notificaciones/envios/${x.id}/reintentar`, {})).envio; aviso(p.querySelector('#hMsg'), r.estado === 'enviado' ? 'ok' : 'mal', r.estado === 'enviado' ? 'Enviado.' : (r.error || 'No salió.')); setTimeout(() => { if (r.estado === 'enviado') { Nucleo.cerrar(); Nucleo.ir('avisos'); } else b.disabled = false; }, 800); }
        catch (e) { b.disabled = false; aviso(p.querySelector('#hMsg'), 'mal', e.message); } };
    }

    // ------------------------------------------------------------------ canales
    async function verCanales(c) {
      const C = (await api('GET', 'm/notificaciones/config')).config, cfg = puede('notificaciones.configurar');
      const est = (ok, t) => `<span class="et ${ok ? 'verde' : 'gris'}">${t}</span>`;
      c.innerHTML = `<div class="tarjeta"><h2>Correo ${est(C.correo.listo, C.correo.listo ? 'Listo' : 'Sin configurar')}</h2>
          <p class="mini">${C.correo.listo ? `Los avisos salen desde <b>${h(C.correo.remitente)}</b>, con el mismo servidor de correo que usa el nodo para recuperar claves.` : 'Usa el servidor de correo del nodo. Se carga en <b>Configuración → Correo saliente</b>.'}</p></div>
        <div class="tarjeta"><h2>Telegram ${est(C.telegram.listo, C.telegram.listo ? 'Listo' : 'Sin configurar')}</h2>
          <p class="mini">1) En Telegram, hablá con <b>@BotFather</b>, creá un bot y copiá el token. 2) Agregá el bot al grupo o escribile «hola». 3) En cada regla poné el número de ese chat. El token se guarda en este nodo y no se vuelve a mostrar.</p>
          ${cfg ? `<div class="filtros"><div style="flex:2 1 260px"><label>Token del bot</label><input type="password" id="cTok" autocomplete="new-password" placeholder="${C.telegram.listo ? 'Cargado · escribí uno nuevo para cambiarlo' : 'Pegá acá el token'}"></div><button class="b" id="cTokG">Guardar</button>${C.telegram.listo ? '<button class="b sec" id="cTokB">Quitar</button>' : ''}</div>` : ''}</div>
        <div class="tarjeta"><h2>Webhook ${est(true, 'Listo')} ${C.webhook.firmado ? est(true, 'Firmado') : ''}</h2>
          <p class="mini">El aviso se entrega como JSON por HTTP a la dirección de cada regla. Con una clave de firma, cada entrega lleva la cabecera <b>X-iProy-Firma</b> (HMAC-SHA256 del cuerpo) para que quien recibe compruebe que viene de este nodo.</p>
          ${cfg ? `<div class="filtros"><div style="flex:2 1 260px"><label>Clave de firma (16 a 128 caracteres)</label><input type="password" id="cSec" autocomplete="new-password" placeholder="${C.webhook.firmado ? 'Cargada · escribí una nueva para cambiarla' : 'Opcional'}"></div><button class="b" id="cSecG">Guardar</button>${C.webhook.firmado ? '<button class="b sec" id="cSecB">Quitar</button>' : ''}</div>` : ''}</div>
        <div class="tarjeta"><h2>Reintentos</h2><p class="mini">Si un canal no responde, el aviso queda en cola y se reintenta a los ${h(C.reintentos_min.join(', '))} minutos (hasta ${C.max_intentos} intentos). El historial se guarda ${C.retener_dias} días.</p><div id="cMsg"></div></div>`;
      if (!cfg) return;
      const g = (i) => c.querySelector(i), msg = g('#cMsg');
      const guardar = async (d, ok) => { try { await api('PUT', 'm/notificaciones/config', d); aviso(msg, 'ok', ok); msg.scrollIntoView({ block: 'nearest' }); const r2 = await api('GET', 'm/notificaciones/resumen'); Object.assign(R, r2.resumen); setTimeout(pintar, 700); } catch (e) { aviso(msg, 'mal', e.message); msg.scrollIntoView({ block: 'nearest' }); } };
      g('#cTokG').onclick = () => g('#cTok').value.trim() ? guardar({ telegram_token: g('#cTok').value.trim() }, 'Token guardado.') : aviso(msg, 'mal', 'Pegá el token del bot.');
      if (g('#cTokB')) g('#cTokB').onclick = () => guardar({ borrar_telegram_token: true }, 'Token quitado.');
      g('#cSecG').onclick = () => g('#cSec').value.trim() ? guardar({ webhook_secreto: g('#cSec').value.trim() }, 'Clave de firma guardada.') : aviso(msg, 'mal', 'Escribí la clave de firma.');
      if (g('#cSecB')) g('#cSecB').onclick = () => guardar({ borrar_webhook_secreto: true }, 'Clave de firma quitada.');
    }
    pintar();
  } });
})();
