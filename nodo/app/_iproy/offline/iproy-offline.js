/* ============================================================================
 * iproy-offline.js · iProy Portable «Colmena» · v0.5.0 · 30-10-2026
 * Lo inyecta el nodo (auto_prepend) ANTES que mysql-bridge.js y que cualquier otro script.
 * Hace que la app funcione igual con o sin Internet, sin modificar sus archivos:
 *   · fetch, XMLHttpRequest y formularios hacia script.google.com/macros/s/<id>/exec
 *     → /_iproy/gas.php?_dep=<id>&… (el nodo decide: Google, caché local, login local o cola).
 *   · <script src> y <link href> agregados después por JS hacia CDN → copia local /_iproy/cdn/….
 *   · Indicador discreto: «Sin conexión · trabajando en el nodo» y cambios en cola.
 * Como se carga primero, mysql-bridge.js envuelve ESTE fetch: sigue viendo las URL originales
 * de Google, desvía sus lecturas a MySQL y hace sus copias a escritura.php como siempre.
 * Interruptor: localStorage.iproy_offline_off = '1' (todo directo a Google, como en producción).
 * ==========================================================================*/
(function (w, d) {
  'use strict';
  if (w.__IPROY_OFFLINE__) return;
  w.__IPROY_OFFLINE__ = '0.5.0';
  try { if (w.localStorage.getItem('iproy_offline_off') === '1') return; } catch (e) {}

  var RE_GAS = /^https:\/\/script\.google\.com\/macros\/s\/([A-Za-z0-9_-]+)\/exec\/?(\?.*)?$/;
  var RE_CDN = /^https:\/\/(cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net|unpkg\.com|code\.jquery\.com|ajax\.googleapis\.com|fonts\.googleapis\.com|fonts\.gstatic\.com|use\.fontawesome\.com|cdn\.tailwindcss\.com)\//i;

  function abs(u) { try { return new URL(String(u), location.href).href; } catch (e) { return String(u); } }
  /** URL de Google → URL local del nodo (o null si no corresponde). */
  function local(u) {
    var m = abs(u).match(RE_GAS);
    if (!m) return null;
    var q = (m[2] || '').replace(/^\?/, '');
    return '/_iproy/gas.php?_dep=' + encodeURIComponent(m[1]) + (q ? '&' + q : '');
  }
  function cdn(u) { var a = abs(u); return RE_CDN.test(a) ? a.replace(/^https:\/\//i, '/_iproy/cdn/') : null; }
  // v0.4.4: APIs públicas que usa la app (cotización del dólar) → copia del nodo (sirve la última sin red)
  var RE_API = /^https:\/\/(dolarapi\.com|api\.bluelytics\.com\.ar)\//i;
  function api(u) { var a = abs(u); return RE_API.test(a) ? a.replace(/^https:\/\//i, '/_iproy/cdn/') : null; }

  /* ---------------------------------------------------------------- fetch */
  var nativo = w.fetch.bind(w);
  w.fetch = function (input, init) {
    var u = typeof input === 'string' ? input : (input && input.url) || String(input);
    var l = local(u);
    if (!l) {
      var ap = api(u);
      if (ap) { var oa = Object.assign({}, init || {}); oa.mode = 'same-origin'; oa.credentials = 'same-origin'; return nativo(ap, oa); }
      return nativo(input, init);
    }
    var o = {};
    if (input && typeof input === 'object' && typeof Request !== 'undefined' && input instanceof Request) {
      o.method = input.method; o.headers = input.headers;
      if (input.method !== 'GET' && input.method !== 'HEAD') {
        return input.clone().arrayBuffer().then(function (b) {
          o.body = b;
          return nativo(l, Object.assign(o, init || {}, { mode: 'same-origin', credentials: 'same-origin', redirect: 'follow' })).then(contar);
        });
      }
    }
    o = Object.assign(o, init || {});
    o.mode = 'same-origin'; o.credentials = 'same-origin'; o.redirect = 'follow';
    return nativo(l, o).then(contar);
  };

  /* ---------------------------------------------------------------- XMLHttpRequest */
  var xo = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function (m, u) {
    var l = local(u) || api(u);
    if (l) { arguments[1] = l; this.withCredentials = false; }
    return xo.apply(this, arguments);
  };

  /* ---------------------------------------------------------------- formularios
   * Se reescribe la acción en la ÚLTIMA fase del evento (window, burbujeo), así mysql-bridge.js
   * (document, captura) ve todavía la URL de Google y hace su copia a MySQL. */
  function redirigirForm(f) {
    if (!f || !f.getAttribute) return;
    var a = f.getAttribute('action');
    var l = a && local(a);
    if (!l) return;
    f.setAttribute('action', l);
    setTimeout(function () { f.setAttribute('action', a); }, 0);   // se restaura para el próximo envío
  }
  w.addEventListener('submit', function (e) { if (!e.defaultPrevented) redirigirForm(e.target); }, false);
  var fs = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () { redirigirForm(this); return fs.apply(this, arguments); };

  /* ---------------------------------------------------------------- scripts y estilos de CDN agregados por JS */
  function parchar(proto, prop) {
    var dsc = Object.getOwnPropertyDescriptor(proto, prop);
    if (!dsc || !dsc.set) return;
    Object.defineProperty(proto, prop, {
      configurable: true, enumerable: dsc.enumerable, get: dsc.get,
      set: function (v) { var c = cdn(v); return dsc.set.call(this, c || v); }
    });
  }
  try { parchar(HTMLScriptElement.prototype, 'src'); parchar(HTMLLinkElement.prototype, 'href'); } catch (e) {}
  var sa = Element.prototype.setAttribute;
  Element.prototype.setAttribute = function (n, v) {
    var t = this.tagName;
    if ((t === 'SCRIPT' && /^src$/i.test(n)) || (t === 'LINK' && /^href$/i.test(n))) { var c = cdn(v); if (c) v = c; }
    return sa.call(this, n, v);
  };

  /* ---------------------------------------------------------------- indicador de estado */
  var chip = null, est = { online: true, cola: 0, dudosos: 0 };
  function contar(r) {
    var via = r.headers && r.headers.get('X-IPROY-Via');
    if (via === 'cola' || via === 'cache' || via === 'nodo-auth') { est.online = false; pintar(); setTimeout(estado, 500); }
    else if (via === 'google' && !est.online) { est.online = true; pintar(); }
    return r;
  }
  function pintar() {
    if (!d.body) return;
    if (!chip) {
      chip = d.createElement('div');            // v0.4.5: solo informa (las Redes están en Admin › Configuración)
      chip.setAttribute('role', 'status');
      chip.style.cssText = 'position:fixed;right:12px;bottom:12px;z-index:2147483000;padding:8px 14px;border-radius:999px;pointer-events:none;'
        + 'font:600 13px/1.2 system-ui,Segoe UI,Roboto,sans-serif;color:#fff;text-decoration:none;'
        + 'box-shadow:0 2px 10px rgba(13,71,161,.25);background:linear-gradient(135deg,#0d47a1,#1976d2 55%,#42a5f5);display:none';
      d.body.appendChild(chip);
    }
    var txt = '';
    if (!est.online) txt = 'Sin conexión · trabajando en el nodo';
    if (est.cola > 0) txt = (txt ? txt + ' · ' : '') + est.cola + (est.cola === 1 ? ' cambio' : ' cambios') + ' por enviar';
    if (est.dudosos > 0) txt = (txt ? txt + ' · ' : '') + est.dudosos + ' por revisar';
    chip.textContent = txt;
    chip.style.display = txt ? 'block' : 'none';
  }
  function estado() {
    nativo('/_iproy/red.php?estado=1', { cache: 'no-store', credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        est.online = j.online !== false; est.cola = j.cola || 0; est.dudosos = j.dudosos || 0;
        nodo.id = j.node_id || nodo.id; nodo.nombre = j.nombre || nodo.nombre; nodo.local = !!j.local;
        pintar(); decorar();
      })
      .catch(function () {});
  }
  /* ---------------------------------------------------------------- nombre del nodo y Redes dentro de Configuración (v0.4.5) */
  var nodo = { id: '', nombre: '', local: false };
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function rotulo() { return nodo.id ? 'Nodo ' + nodo.id + (nodo.nombre ? ' · ' + nodo.nombre : '') : ''; }
  function decorar() {
    if (!nodo.id) return;
    // 1) Barra superior, junto al usuario: «Nodo ABC · PC Taller»
    var tr = d.querySelector('#topbar .topbar-right');
    if (tr && !d.getElementById('iproyNodoChip')) {
      var c = d.createElement('div');
      c.id = 'iproyNodoChip'; c.className = 'chip chip-blue'; c.title = 'Equipo donde está instalado el sistema (nodo de la colmena)';
      c.innerHTML = '<i class="fas fa-server" style="font-size:.7rem;margin-right:5px;"></i>' + esc(rotulo());
      c.style.marginRight = '8px';
      tr.insertBefore(c, tr.firstChild);
    }
    // 2) Pantalla de ingreso
    var lf = d.querySelector('#loginScreen .login-foot');
    if (lf && !d.getElementById('iproyNodoLogin')) {
      var s1 = d.createElement('div'); s1.id = 'iproyNodoLogin'; s1.style.cssText = 'margin-top:4px;opacity:.8';
      s1.textContent = rotulo(); lf.appendChild(s1);
    }
    // 3) Admin › Configuración: tarjeta «Redes y colmena», disponible aunque no haya Internet
    var pc = d.getElementById('panelConfig');
    if (pc && !d.getElementById('iproyRedesCard')) {
      var k = d.createElement('div');
      k.id = 'iproyRedesCard';
      k.style.cssText = 'margin:0 0 14px;background:#fff;border:1px solid #d6e4f5;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(13,71,161,.08)';
      k.innerHTML = '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;color:#fff;background:linear-gradient(135deg,#0d47a1,#1976d2 55%,#42a5f5)">'
        + '<i class="fas fa-network-wired" style="font-size:1.2rem"></i>'
        + '<div style="flex:1;min-width:200px"><div style="font-weight:700">Redes y colmena · ' + esc(rotulo()) + '</div>'
        + '<div style="font-size:.8rem;opacity:.9">Conexión con los servidores centrales, IP, otros nodos, hub, cola de cambios e ingreso sin Internet. Funciona sin conexión.</div></div>'
        + (nodo.local ? '<button type="button" id="iproyRedesBtn" style="min-height:38px;padding:0 16px;border:1px solid rgba(255,255,255,.6);border-radius:9px;background:rgba(255,255,255,.15);color:#fff;font-weight:600;cursor:pointer">Abrir</button>'
                      : '<span style="font-size:.8rem;opacity:.9">Se configura desde el equipo del nodo</span>')
        + '</div><div id="iproyRedesBox" style="display:none"></div>';
      pc.insertBefore(k, pc.firstChild);
      // 4) Servidor PIM (v0.5): interoperabilidad con otros sistemas; la Consola PIM trabaja solo a través de la API
      var kp = d.createElement('div');
      kp.id = 'iproyPimCard';
      kp.style.cssText = k.style.cssText;
      kp.innerHTML = '<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 16px;color:#fff;background:linear-gradient(135deg,#1565c0,#1e88e5 55%,#64b5f6)">'
        + '<i class="fas fa-project-diagram" style="font-size:1.2rem"></i>'
        + '<div style="flex:1;min-width:200px"><div style="font-weight:700">Servidor PIM · interoperabilidad de mantenimiento</div>'
        + '<div style="font-size:.8rem;opacity:.9">Órdenes, solicitudes, inventario con riesgo (IRU), telemetría y trazabilidad con el protocolo PIM (HL7 FHIR R4). Refleja iProy cada 5 minutos sin modificarlo.</div></div>'
        + (nodo.local ? '<a href="/pim/consola/" target="_blank" rel="noopener" style="min-height:38px;display:inline-flex;align-items:center;padding:0 16px;border:1px solid rgba(255,255,255,.6);border-radius:9px;background:rgba(255,255,255,.15);color:#fff;font-weight:600;text-decoration:none">Abrir Consola PIM</a>'
                      : '<span style="font-size:.8rem;opacity:.9">Consola disponible en el equipo del nodo</span>')
        + '</div>';
      pc.insertBefore(kp, k.nextSibling);
      var b = d.getElementById('iproyRedesBtn');
      if (b) b.addEventListener('click', function () {
        var box = d.getElementById('iproyRedesBox');
        var abierto = box.style.display !== 'none';
        if (!abierto && !box.firstChild) box.innerHTML = '<iframe src="/_iproy/red.php?embebido=1" title="Redes del nodo" style="width:100%;height:75vh;border:0;display:block"></iframe>';
        box.style.display = abierto ? 'none' : 'block';
        b.textContent = abierto ? 'Abrir' : 'Cerrar';
      });
    }
  }
  try { new MutationObserver(function () { if (nodo.id) decorar(); }).observe(d.documentElement, { childList: true, subtree: true }); } catch (e) {}

  w.IPROY_OFFLINE = { version: '0.5.0', estado: est, nodo: nodo, refrescar: estado };
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', estado); else estado();
  setInterval(estado, 30000);
  w.addEventListener('online', estado); w.addEventListener('offline', function () { est.online = false; pintar(); });
})(window, document);
