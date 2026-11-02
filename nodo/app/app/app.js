// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Aplicación del nodo · cáscara: sesión, navegación y API window.Nucleo para las pestañas que aporta cada módulo (ui.js).
'use strict';
(() => {
  const $ = (s, r = document) => r.querySelector(s);
  const h = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pestanas = []; const tarjetas = [];
  let S = null; let actual = null; const cargados = new Set();

  async function api(metodo, ruta, cuerpo) {
    const o = { method: metodo, headers: { 'X-Nucleo': '1', Accept: 'application/json' }, credentials: 'same-origin' };
    if (cuerpo !== undefined) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(cuerpo); }
    const r = await fetch('/api/' + ruta, o);
    const j = await r.json().catch(() => ({ ok: false, error: 'Respuesta inválida (HTTP ' + r.status + ')' }));
    if (r.status === 401 && ruta !== 'sesion') { S = null; pintar(); throw new Error('La sesión venció: ingresá de nuevo'); }
    if (!r.ok || j.ok === false) throw new Error(j.error || 'HTTP ' + r.status);
    return j;
  }
  async function pim(metodo, ruta, cuerpo, cab = {}) {
    const o = { method: metodo, headers: Object.assign({ 'X-Nucleo': '1', Accept: 'application/fhir+json' }, cab), credentials: 'same-origin' };
    if (cuerpo !== undefined) { o.headers['Content-Type'] = 'application/fhir+json'; o.body = JSON.stringify(cuerpo); }
    const r = await fetch('/api/pim/' + ruta, o);
    const j = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error((j.issue && j.issue[0] && j.issue[0].diagnostics) || j.error || 'HTTP ' + r.status);
    return { cuerpo: j, cab: r.headers };
  }
  const fecha = (s) => { if (!s) return '—'; const d = new Date(String(s).replace(' ', 'T')); return isNaN(d) ? h(s) : d.toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' }); };
  const aviso = (el, tipo, msg) => { el.innerHTML = `<div class="aviso ${tipo}">${h(msg)}</div>`; };
  function tabla(cols, filas, alClic) {
    if (!filas.length) return '<p class="mini">Sin datos.</p>';
    const id = 't' + Math.random().toString(36).slice(2);
    setTimeout(() => { const t = document.getElementById(id); if (t && alClic) t.querySelectorAll('tr[data-i]').forEach((tr) => tr.onclick = () => alClic(filas[+tr.dataset.i])); });
    return `<table id="${id}"><thead><tr>${cols.map((c) => `<th>${h(c.t)}</th>`).join('')}</tr></thead><tbody>${filas.map((f, i) =>
      `<tr data-i="${i}" class="${alClic ? 'clic' : ''}">${cols.map((c) => `<td>${c.html ? c.v(f) : h(c.v(f))}</td>`).join('')}</tr>`).join('')}</tbody></table>`;
  }
  function panel(titulo, html) {
    $('#panel').innerHTML = `<div style="display:flex;align-items:center;gap:8px"><h2 style="margin:0;color:var(--az);font-size:18px;flex:1">${h(titulo)}</h2><button class="b sec" id="pCerrar">Cerrar</button></div><div id="pCuerpo" style="margin-top:12px">${html}</div>`;
    $('#panel').classList.add('abierto'); $('#velo').classList.add('abierto');
    $('#pCerrar').onclick = cerrar; return $('#pCuerpo');
  }
  function cerrar() { $('#panel').classList.remove('abierto'); $('#velo').classList.remove('abierto'); }
  /** Formulario simple: campos [{n, t, tipo: text|number|date|select|textarea, op: [[v, texto]], v, req}] */
  function form(campos, boton, alEnviar) {
    const id = 'f' + Math.random().toString(36).slice(2);
    setTimeout(() => {
      const f = document.getElementById(id); if (!f) return;
      f.onsubmit = async (e) => {
        e.preventDefault(); const d = {}; const msg = f.querySelector('.fmsg'); const b = f.querySelector('button[type=submit]');
        campos.forEach((c) => { const x = f.elements[c.n]; if (!x) return; d[c.n] = c.tipo === 'number' ? (x.value === '' ? null : Number(x.value)) : c.tipo === 'check' ? x.checked : x.value; });
        b.disabled = true; try { await alEnviar(d, msg); } catch (x) { aviso(msg, 'mal', x.message); } finally { b.disabled = false; }
      };
    });
    return `<form id="${id}"><div class="grid2">${campos.map((c) => `<div style="${c.tipo === 'textarea' ? 'grid-column:1/-1' : ''}"><label>${h(c.t)}${c.req ? ' *' : ''}</label>${
      c.tipo === 'select' ? `<select name="${c.n}">${c.op.map(([v, t]) => `<option value="${h(v)}" ${String(v) === String(c.v ?? '') ? 'selected' : ''}>${h(t)}</option>`).join('')}</select>`
      : c.tipo === 'textarea' ? `<textarea name="${c.n}">${h(c.v ?? '')}</textarea>`
      : c.tipo === 'check' ? `<input type="checkbox" name="${c.n}" style="width:auto;min-height:0" ${c.v ? 'checked' : ''}>`
      : `<input name="${c.n}" type="${c.tipo || 'text'}" ${c.tipo === 'number' ? 'step="any"' : ''} value="${h(c.v ?? '')}" ${c.req ? 'required' : ''}>`}</div>`).join('')}</div>
      <div class="acciones"><button class="b" type="submit">${h(boton)}</button></div><div class="fmsg"></div></form>`;
  }
  const puede = (cap) => !!S && (S.usuario.rol === 'AD' || S.capacidades.includes(cap));

  window.Nucleo = {
    api, pim, h, fecha, aviso, tabla, panel, cerrar, form, puede,
    get sesion() { return S; },
    /** Un módulo registra su pestaña: {id, titulo, orden, cap, render(el)} */
    pestana(p) { pestanas.push(p); pestanas.sort((a, b) => (a.orden ?? 50) - (b.orden ?? 50)); },
    /** Un módulo agrega una tarjeta al Tablero: async (el) => {...} */
    tablero(fn) { tarjetas.push(fn); },
  };

  // ---------------------------------------------------------------- pestañas del núcleo
  Nucleo.pestana({ id: 'tablero', titulo: 'Tablero', orden: 0, render: async (el) => {
    el.innerHTML = '<div id="tbTarj"></div>';
    for (const fn of tarjetas) { const d = document.createElement('div'); $('#tbTarj').appendChild(d); try { await fn(d); } catch (x) { aviso(d, 'mal', x.message); } }
    if (puede('sistema.ver')) {
      const s = (await api('GET', 'proc/sistema')).sistema; const d = document.createElement('div'); $('#tbTarj').appendChild(d);
      d.innerHTML = `<div class="tarjeta"><h2>Nodo</h2><div class="kpis">
        <div class="kpi"><b>${h(s.nodo.id)}</b><span>${h(s.nodo.nombre)}</span></div>
        <div class="kpi"><b>${s.modulos.cargados.length}</b><span>módulos cargados</span></div>
        <div class="kpi"><b>${s.vinculos_activos}</b><span>vínculos de colmena</span></div>
        <div class="kpi"><b>${s.diario}</b><span>asientos del diario firmado</span></div></div>
        ${s.contaminado.length ? `<div class="aviso mal">Nodo «contaminado»: hay módulos con licencia no libre (${h(s.contaminado.join(', '))}).</div>` : ''}</div>`;
    }
  } });

  Nucleo.pestana({ id: 'colmena', titulo: 'Colmena', orden: 80, cap: 'colmena.ver', render: async (el) => {
    const j = await api('GET', 'colmena/vinculos');
    const est = { activo: 'verde', entrante: 'nar', saliente: 'ama', revocado: 'gris' };
    el.innerHTML = `<div class="tarjeta"><h2>Este nodo</h2><dl><dt>Nodo</dt><dd>${h(j.yo.node_id)} · ${h(j.yo.nombre)} (${h(j.yo.producto)} ${h(j.yo.version)})</dd>
      <dt>Huella</dt><dd><code>${h(j.yo.huella)}</code> <span class="mini">— dictala por teléfono al otro administrador antes de que acepte</span></dd>
      <dt>Dirección</dt><dd>${h(j.yo.url)}</dd><dt>Hub de relevo</dt><dd>${j.hub.url ? h(j.hub.url) + (j.hub.activo ? ' · activo' : ' · apagado') : 'sin configurar (solo intranet)'}</dd></dl></div>
      <div class="tarjeta"><h2>Vínculos</h2>${tabla([
        { t: 'Nodo', v: (v) => v.par + ' · ' + (v.nombre || '') }, { t: 'Producto', v: (v) => v.producto || '' },
        { t: 'Estado', html: true, v: (v) => `<span class="et ${est[v.estado] || ''}">${h(v.estado)}</span>` }, { t: 'Rol PIM que le damos', v: (v) => v.rol_pim },
        { t: 'Huella', v: (v) => v.huella }, { t: 'Último contacto', v: (v) => fecha(v.ultimo_contacto) }], j.vinculos, (v) => detalleVinculo(v))}
      ${puede('colmena.admin') ? `<h2 style="margin-top:16px">Pedir un vínculo</h2>${form([{ n: 'destino', t: 'URL del otro nodo (https://IP:8443) o su identificador (por el hub)', req: true }], 'Pedir vínculo', async (d, m) => {
        const r = await api('POST', 'colmena/vincular', d); aviso(m, 'ok', `Pedido a ${r.par} (${r.producto || ''}). Huella del otro nodo: ${r.huella}. ${r.aviso}`); })}` : ''}</div>`;
  } });
  function detalleVinculo(v) {
    const p = panel('Vínculo con ' + v.par, `<dl><dt>Nombre</dt><dd>${h(v.nombre)}</dd><dt>Producto</dt><dd>${h(v.producto)}</dd><dt>Estado</dt><dd>${h(v.estado)}</dd>
      <dt>Huella</dt><dd><code>${h(v.huella)}</code></dd><dt>Dirección</dt><dd>${h(v.url || 'por el hub')}</dd><dt>Rol PIM</dt><dd>${h(v.rol_pim)}</dd></dl>
      <div class="acciones">${v.estado === 'entrante' && puede('colmena.admin') ? '<button class="b" data-a="aceptar">Aceptar (rol consulta)</button>' : ''}
      ${v.estado === 'activo' ? '<button class="b sec" data-a="ping">Probar</button><button class="b sec" data-a="rpc">Contar sus activos y órdenes</button>' : ''}
      ${v.estado !== 'revocado' && puede('colmena.admin') ? '<button class="b peligro" data-a="revocar">Revocar</button>' : ''}</div><div id="vMsg"></div>`);
    p.querySelectorAll('button[data-a]').forEach((b) => b.onclick = async () => {
      const m = $('#vMsg');
      try {
        if (b.dataset.a === 'aceptar') { await api('POST', `colmena/vinculos/${v.par}/aceptar`, { rol: 'CI' }); aviso(m, 'ok', 'Vínculo activo.'); }
        if (b.dataset.a === 'revocar') { await api('POST', `colmena/vinculos/${v.par}/revocar`); aviso(m, 'ok', 'Vínculo revocado.'); }
        if (b.dataset.a === 'ping') { const r = await api('POST', `colmena/vinculos/${v.par}/ping`); aviso(m, 'ok', `Respondió (${r.via}, ${r.ms} ms).`); }
        if (b.dataset.a === 'rpc') {
          aviso(m, 'info', 'Consultando la API PIM del otro nodo…');
          const a = (await api('POST', 'colmena/rpc', { par: v.par, metodo: 'GET', ruta: 'Device?_summary=count', esperar: 60 })).rpc;
          const t = (await api('POST', 'colmena/rpc', { par: v.par, metodo: 'GET', ruta: 'Task?_summary=count', esperar: 60 })).rpc;
          aviso(m, a.estado === 'respondido' ? 'ok' : 'mal', a.estado === 'respondido' ? `Activos: ${a.respuesta.cuerpo.total ?? '?'} · Órdenes: ${t.respuesta?.cuerpo?.total ?? '?'} (vía ${a.via})` : `Estado: ${a.estado}`);
        }
      } catch (x) { aviso(m, 'mal', x.message); }
    });
  }

  Nucleo.pestana({ id: 'sistema', titulo: 'Sistema', orden: 90, cap: 'sistema.ver', render: async (el) => {
    const [m, t, d] = await Promise.all([api('GET', 'proc/modulos'), api('GET', 'proc/tareas'), api('GET', 'proc/diario?n=40')]);
    const ce = { cargado: 'verde', desactivado: 'gris', error: 'rojo', dependencia: 'nar', ciclo: 'rojo' };
    el.innerHTML = `<div class="tarjeta"><h2>Módulos</h2>${tabla([{ t: 'Módulo', v: (x) => x.id + ' ' + x.version }, { t: 'Nombre', v: (x) => x.nombre },
        { t: 'Estabilidad', v: (x) => x.estabilidad }, { t: 'Licencia', v: (x) => x.licencia + (x.libre ? '' : ' ⚠') },
        { t: 'Estado', html: true, v: (x) => `<span class="et ${ce[x.estado] || ''}">${h(x.estado)}</span>` + (x.error ? `<div class="mini">${h(x.error)}</div>` : '') }], m.modulos, (x) => detalleModulo(x))}</div>
      <div class="tarjeta"><h2>Tareas programadas</h2>${tabla([{ t: 'Tarea', v: (x) => x.id }, { t: 'Cada', v: (x) => x.cada_s + ' s' }, { t: 'Última', v: (x) => fecha(x.ultima) },
        { t: 'Duración', v: (x) => (x.duracion_ms ?? '—') + ' ms' }, { t: 'Resultado', html: true, v: (x) => `<span class="et ${+x.ok ? 'verde' : 'rojo'}">${h(x.resultado || '')}</span>` }], t.tareas)}</div>
      <div class="tarjeta"><h2>Diario firmado</h2><div class="acciones" style="margin:0 0 10px"><button class="b sec" id="dVer">Verificar cadena y firmas</button></div><div id="dMsg"></div>
      ${tabla([{ t: '#', v: (x) => x.id }, { t: 'Fecha', v: (x) => fecha(x.ts) }, { t: 'Tipo', v: (x) => x.tipo }, { t: 'Quién', v: (x) => x.actor }, { t: 'Sobre', v: (x) => x.objeto || '' },
        { t: 'Detalle', v: (x) => JSON.stringify(x.detalle).slice(0, 140) }], d.diario)}</div>`;
    $('#dVer').onclick = async () => { const r = await api('POST', 'proc/diario/verificar'); aviso($('#dMsg'), r.ok ? 'ok' : 'mal', r.ok ? `Cadena íntegra: ${r.asientos} asientos firmados.` : `Alteración en el asiento ${r.roto_en}: ${r.motivo}`); };
  } });
  async function detalleModulo(x) {
    const p = panel('Módulo ' + x.id, `<p>${h(x.descripcion)}</p><dl><dt>Versión</dt><dd>${h(x.version)} · ABI ${h(x.abi)} · ${h(x.estabilidad)}</dd><dt>Licencia</dt><dd>${h(x.licencia)}</dd>
      <dt>Requiere</dt><dd>${h((x.requiere || []).join(', ') || '—')}</dd><dt>Migraciones</dt><dd>${h((x.migraciones || []).map((m) => m.archivo).join(', ') || '—')}</dd></dl>
      ${puede('modulos.admin') ? `<h3>Configuración</h3><textarea id="mCfg" style="min-height:220px;font-family:monospace;font-size:13px"></textarea>
      <div class="acciones"><button class="b" id="mGuardar">Guardar configuración</button><button class="b sec" id="mAct">${x.estado === 'desactivado' ? 'Activar' : 'Desactivar'}</button></div>` : ''}<div id="mMsg"></div>`);
    if (!puede('modulos.admin')) return;
    $('#mCfg').value = JSON.stringify((await api('GET', `modulos/${x.id}/config`)).config, null, 2);
    $('#mGuardar').onclick = async () => { try { await api('PUT', `modulos/${x.id}/config`, { config: JSON.parse($('#mCfg').value) }); aviso($('#mMsg'), 'ok', 'Guardada. Se aplica en el próximo ciclo.'); } catch (e) { aviso($('#mMsg'), 'mal', e.message); } };
    $('#mAct').onclick = async () => { try { await api('POST', `modulos/${x.id}/${x.estado === 'desactivado' ? 'activar' : 'desactivar'}`); location.reload(); } catch (e) { aviso($('#mMsg'), 'mal', e.message); } };
  }

  Nucleo.pestana({ id: 'usuarios', titulo: 'Usuarios', orden: 95, cap: 'usuarios.admin', render: async (el) => {
    const j = await api('GET', 'usuarios'); const roles = Object.entries(j.roles);
    el.innerHTML = `<div class="tarjeta"><h2>Usuarios del nodo</h2>${tabla([{ t: 'Usuario', v: (u) => u.usuario }, { t: 'Nombre', v: (u) => u.nombre }, { t: 'Rol', v: (u) => u.rol + ' · ' + j.roles[u.rol] },
      { t: 'Organización', v: (u) => u.organizacion || '' }, { t: 'Activo', v: (u) => +u.activo ? 'sí' : 'no' }, { t: 'Último ingreso', v: (u) => fecha(u.ultimo_ingreso) }], j.usuarios)}
      <h2 style="margin-top:16px">Nuevo usuario</h2>${form([{ n: 'usuario', t: 'Usuario', req: true }, { n: 'nombre', t: 'Nombre visible', req: true },
        { n: 'rol', t: 'Rol', tipo: 'select', op: roles }, { n: 'organizacion', t: 'Organización (código PIM, solo Ejecutor)' }, { n: 'clave', t: 'Contraseña inicial (10+)', tipo: 'password', req: true }],
      'Crear', async (d, m) => { await api('POST', 'usuarios', d); aviso(m, 'ok', 'Usuario creado.'); setTimeout(() => ir('usuarios'), 800); })}</div>`;
  } });

  // ---------------------------------------------------------------- sesión y navegación
  function pintar() {
    const r = $('#raiz');
    if (!S) {
      r.innerHTML = `<div class="login"><img src="logo.png" alt=""><h2 id="lProd">Ingresar</h2><form id="fLogin"><div class="campo"><label>Usuario</label><input name="u" autocomplete="username" required></div>
        <div class="campo"><label>Contraseña</label><input name="c" type="password" autocomplete="current-password" required></div><button class="b" style="width:100%">Ingresar</button><div id="lMsg"></div></form></div>`;
      api('GET', 'sesion').then((j) => { if (j.producto) $('#lProd').textContent = j.producto + ' · ' + (j.nodo?.nombre || ''); }).catch(() => {});
      $('#fLogin').onsubmit = async (e) => {
        e.preventDefault();
        try { await api('POST', 'sesion', { usuario: e.target.u.value.trim(), clave: e.target.c.value }); await iniciar(); } catch (x) { aviso($('#lMsg'), 'mal', x.message); }
      };
      return;
    }
    const vis = pestanas.filter((p) => !p.cap || puede(p.cap));
    r.innerHTML = `<header><div class="fila"><img src="logo.png" alt=""><h1>${h(S.producto.nombre)}</h1><span class="chip">Nodo ${h(S.nodo.id)} · ${h(S.nodo.nombre)}</span>
      <div class="der"><span class="chip">${h(S.usuario.nombre)} · ${h(S.usuario.rol)}</span><button id="bSalir">Salir</button></div></div>
      <nav>${vis.map((p) => `<button data-p="${p.id}">${h(p.titulo)}</button>`).join('')}</nav></header><main id="main"></main>`;
    r.querySelectorAll('nav button').forEach((b) => b.onclick = () => ir(b.dataset.p));
    $('#bSalir').onclick = async () => { await api('DELETE', 'sesion').catch(() => {}); S = null; pintar(); };
    ir(actual && vis.some((p) => p.id === actual) ? actual : (location.hash.slice(1) || 'tablero'));
  }
  async function ir(id) {
    const p = pestanas.find((x) => x.id === id && (!x.cap || puede(x.cap))) || pestanas[0];
    actual = p.id; location.hash = p.id;
    document.querySelectorAll('nav button').forEach((b) => b.classList.toggle('activo', b.dataset.p === p.id));
    const el = $('#main'); el.innerHTML = '<p class="mini">Cargando…</p>';
    try { await p.render(el); } catch (x) { aviso(el, 'mal', x.message); }
  }
  Nucleo.ir = ir;
  async function iniciar() {
    const j = await api('GET', 'sesion');
    if (!j.ok) { S = null; pintar(); return; }
    S = j;
    for (const u of j.ui) {   // cada módulo con interfaz aporta su ui.js (se carga en orden, una sola vez)
      if (cargados.has(u.modulo)) continue; cargados.add(u.modulo);
      await new Promise((ok) => { const s = document.createElement('script'); s.src = `/api/modulos/${u.modulo}/ui.js?v=${encodeURIComponent(u.version)}`; s.onload = ok; s.onerror = ok; document.head.appendChild(s); });
    }
    const p = j.producto;
    $('#pie').innerHTML = `${h(p.nombre)} ${h(p.version)} · software libre <a href="/api/fuente">${h(p.licencia)}</a>${p.fuente ? ` · <a href="${h(p.fuente)}" rel="noopener">código fuente</a>` : ''}${p.base ? ` · basado en ${h(p.base)}` : ''}`;
    pintar();
  }
  $('#velo').onclick = cerrar;
  iniciar().catch(() => { S = null; pintar(); });
})();
