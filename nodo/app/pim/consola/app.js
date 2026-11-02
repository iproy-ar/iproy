/* iProy · Consola PIM v1.0 · 30-10-2026
 * Cliente web del Servidor PIM. Todo pasa por la API (/pim/r4) a través de proxy.php, que firma como el cliente «consola-local» (rol GM).
 * Sin dependencias externas: funciona sin Internet. */
(function () {
  'use strict';
  var NS = 'https://iproy.com.ar/fhir';
  var SD = NS + '/StructureDefinition/', CS = NS + '/CodeSystem/';
  var ESTADOS = ['solicitada', 'en-revision', 'asignada', 'aceptada', 'lista', 'en-ejecucion', 'en-espera', 'finalizada', 'conformada', 'rechazada', 'anulada'];
  // Tabla 9-2 (igual que el servidor; el servidor es quien valida)
  var TRANS = {
    'solicitada': ['en-revision', 'asignada', 'rechazada', 'anulada'], 'en-revision': ['asignada', 'rechazada', 'anulada'],
    'asignada': ['aceptada', 'en-revision', 'anulada'], 'aceptada': ['lista', 'en-ejecucion', 'en-espera', 'anulada'],
    'lista': ['en-ejecucion', 'en-espera', 'anulada'], 'en-ejecucion': ['en-espera', 'finalizada'],
    'en-espera': ['en-ejecucion', 'aceptada', 'anulada'], 'finalizada': ['conformada', 'en-ejecucion'],
    'conformada': [], 'rechazada': [], 'anulada': []
  };
  var CON_MOTIVO = ['en-espera', 'rechazada', 'anulada', 'en-revision'];
  var PRIO = { stat: 'Inmediata', asap: 'Lo antes posible', urgent: 'Urgente', routine: 'Rutina' };
  var TIPOS = ['correctivo-inmediato', 'correctivo-diferido', 'preventivo-sistematico', 'preventivo-condicional', 'mejora', 'ensayo-reglamentario'];
  var SIST = null, ORGS = null;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var main = $('#main');

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function fecha(s) { if (!s) return '—'; var d = new Date(s); return isNaN(d) ? s : d.toLocaleString('es-AR', { dateStyle: 'short', timeStyle: 'short' }); }
  function idDe(ref) { return ref ? String(ref).split('/').pop() : ''; }
  function ext(r, n) { return (r.extension || []).filter(function (e) { return e.url === SD + n; })[0]; }
  function estadoDe(t) { return (t.businessStatus && t.businessStatus.coding && t.businessStatus.coding[0].code) || ''; }
  function legado(r) { return ((r.meta && r.meta.tag) || []).some(function (t) { return t.code === 'legado'; }); }
  function aviso(el, tipo, txt) { el.innerHTML = '<div class="aviso ' + tipo + '">' + esc(txt) + '</div>'; }

  /* ---------------------------------------------------------------- API */
  // Cola: como máximo 3 pedidos a la vez (el nodo puede correr en equipos de 2 núcleos con pocos hilos de PHP)
  var enCurso = 0, cola = [];
  function api(metodo, ruta, cuerpo, version) {
    return new Promise(function (ok, mal) { cola.push([metodo, ruta, cuerpo, version, ok, mal]); siguiente(); });
  }
  function siguiente() {
    if (enCurso >= 3 || !cola.length) return;
    var x = cola.shift(); enCurso++;
    pedir(x[0], x[1], x[2], x[3]).then(x[4], x[5]).then(function () { enCurso--; siguiente(); });
  }
  function pedir(metodo, ruta, cuerpo, version) {
    var h = { 'X-PIM-Consola': '1', 'Accept': 'application/fhir+json' };
    if (cuerpo) h['Content-Type'] = 'application/fhir+json';
    if (version) h['If-Match'] = 'W/"' + version + '"';
    return fetch('proxy.php?r=' + encodeURIComponent(ruta), { method: metodo, headers: h, body: cuerpo ? JSON.stringify(cuerpo) : undefined, credentials: 'same-origin' })
      .then(function (r) {
        return r.text().then(function (t) {
          var j = {}; try { j = t ? JSON.parse(t) : {}; } catch (e) { j = {}; }
          if (!r.ok) { var e = new Error((j.issue && j.issue[0] && j.issue[0].diagnostics) || ('HTTP ' + r.status)); e.http = r.status; throw e; }
          j._task = r.headers.get('X-PIM-Task'); return j;
        });
      });
  }
  function lista(b) { return (b.entry || []).map(function (e) { return e.resource; }); }
  function total(ruta) { return api('GET', ruta + (ruta.indexOf('?') < 0 ? '?' : '&') + '_summary=count').then(function (b) { return b.total || 0; }); }
  function orgs() {
    if (ORGS) return Promise.resolve(ORGS);
    return api('GET', 'Organization?_count=200').then(function (b) { ORGS = lista(b); return ORGS; });
  }
  function nombre(ref) { return ref ? (ref.display || ref.reference) : '—'; }

  /* ---------------------------------------------------------------- navegación */
  var vistas = {};
  $('#nav').addEventListener('click', function (e) {
    var b = e.target.closest('button'); if (!b) return;
    ir(b.getAttribute('data-v'));
  });
  function ir(v, arg) {
    [].forEach.call(document.querySelectorAll('#nav button'), function (x) { x.classList.toggle('activo', x.getAttribute('data-v') === v); });
    cerrarPanel();
    main.innerHTML = '<div class="tarjeta">Cargando…</div>';
    vistas[v](arg);
  }

  /* ---------------------------------------------------------------- tablero */
  vistas.tablero = function () {
    main.innerHTML = '<div class="tarjeta"><h2>Órdenes de trabajo por estado</h2><div class="kpis" id="kE"></div></div>'
      + '<div class="tarjeta"><h2>Riesgo de los activos (IRU) y señales</h2><div class="kpis" id="kR"></div></div>'
      + '<div class="tarjeta"><h2>Últimas órdenes modificadas</h2><div id="ult"></div></div>';
    ESTADOS.forEach(function (s) {
      var d = document.createElement('div'); d.className = 'kpi'; d.innerHTML = '<b>…</b><span>' + esc(s) + '</span>';
      d.onclick = function () { ir('ordenes', { estado: s }); };
      $('#kE').appendChild(d);
      total('Task?business-status=' + s).then(function (n) { d.firstChild.textContent = n; }).catch(function () { d.firstChild.textContent = '—'; });
    });
    ['A', 'B', 'C', 'D'].forEach(function (b) {
      var d = document.createElement('div'); d.className = 'kpi'; d.innerHTML = '<b>…</b><span>Activos banda ' + b + '</span>';
      $('#kR').appendChild(d);
      api('GET', 'RiskAssessment?banda=' + b + '&_count=0').then(function (x) { d.firstChild.textContent = x.total; }).catch(function () { d.firstChild.textContent = '—'; });
    });
    [['Solicitudes activas', 'ServiceRequest?status=active'], ['Mediciones fuera de rango', 'Observation?interpretation=H,L'], ['Activos', 'Device'], ['Locales', 'Location']].forEach(function (k) {
      var d = document.createElement('div'); d.className = 'kpi'; d.innerHTML = '<b>…</b><span>' + esc(k[0]) + '</span>';
      $('#kR').appendChild(d);
      total(k[1]).then(function (n) { d.firstChild.textContent = n; }).catch(function () { d.firstChild.textContent = '—'; });
    });
    api('GET', 'Task?_sort=-_lastUpdated&_count=8').then(function (b) { $('#ult').innerHTML = tablaOrdenes(lista(b)); enlazarFilas($('#ult')); })
      .catch(function (e) { aviso($('#ult'), 'mal', e.message); });
  };

  /* ---------------------------------------------------------------- órdenes */
  function tablaOrdenes(l) {
    if (!l.length) return '<div class="aviso info">No hay órdenes con esos filtros.</div>';
    return '<table><thead><tr><th>Número</th><th>Estado</th><th>Prioridad</th><th>Categoría</th><th>Lugar / activo</th><th>Responsable</th><th>Alta</th></tr></thead><tbody>'
      + l.map(function (t) {
        return '<tr class="clic" data-id="' + esc(t.id) + '"><td><b>' + esc(t.identifier[0].value) + '</b>' + (legado(t) ? ' <span class="et leg" title="Reflejada de iProy: se gestiona allí">iProy</span>' : '') + '</td>'
          + '<td><span class="et">' + esc(estadoDe(t)) + '</span></td><td><span class="et ' + esc(t.priority) + '">' + esc(PRIO[t.priority] || t.priority) + '</span></td>'
          + '<td>' + esc((t.code && (t.code.text || t.code.coding[0].code)) || '—') + '</td>'
          + '<td>' + esc(t.focus ? nombre(t.focus) : nombre(t.location)) + '</td><td>' + esc(nombre(t.owner)) + '</td><td class="mini">' + fecha(t.authoredOn) + '</td></tr>';
      }).join('') + '</tbody></table>';
  }
  function enlazarFilas(el) { el.addEventListener('click', function (e) { var tr = e.target.closest('tr.clic'); if (tr) verOrden(tr.getAttribute('data-id')); }); }

  vistas.ordenes = function (f) {
    f = f || {};
    main.innerHTML = '<div class="tarjeta"><h2>Órdenes de trabajo</h2><div class="filtros">'
      + '<div class="campo"><label>Estado</label><select id="fE"><option value="">Todos</option>' + ESTADOS.map(function (s) { return '<option' + (f.estado === s ? ' selected' : '') + '>' + s + '</option>'; }).join('') + '</select></div>'
      + '<div class="campo"><label>Prioridad</label><select id="fP"><option value="">Todas</option>' + Object.keys(PRIO).map(function (p) { return '<option value="' + p + '">' + PRIO[p] + '</option>'; }).join('') + '</select></div>'
      + '<div class="campo"><label>Número</label><input id="fN" placeholder="p. ej. TCH-000004 o 24204"></div>'
      + '<button class="b" id="fB">Buscar</button></div><div id="res"></div><div class="pag"><button class="b sec" id="pA" disabled>‹ Anterior</button><span class="mini" id="pI"></span><button class="b sec" id="pS" disabled>Siguiente ›</button></div></div>';
    var off = 0, N = 25;
    function cargar() {
      var q = ['_count=' + N, '_offset=' + off];
      if ($('#fE').value) q.push('business-status=' + $('#fE').value);
      if ($('#fP').value) q.push('priority=' + $('#fP').value);
      if ($('#fN').value.trim()) q.push('identifier=' + encodeURIComponent($('#fN').value.trim()));
      $('#res').innerHTML = 'Cargando…';
      api('GET', 'Task?' + q.join('&')).then(function (b) {
        $('#res').innerHTML = tablaOrdenes(lista(b));
        $('#pI').textContent = b.total ? (off + 1) + '–' + Math.min(off + N, b.total) + ' de ' + b.total : '';
        $('#pA').disabled = off === 0; $('#pS').disabled = off + N >= (b.total || 0);
      }).catch(function (e) { aviso($('#res'), 'mal', e.message); });
    }
    enlazarFilas($('#res'));
    $('#fB').onclick = function () { off = 0; cargar(); };
    $('#fN').onkeydown = function (e) { if (e.key === 'Enter') { off = 0; cargar(); } };
    $('#pA').onclick = function () { off = Math.max(0, off - N); cargar(); };
    $('#pS').onclick = function () { off += N; cargar(); };
    cargar();
  };

  /* ---------------------------------------------------------------- detalle de orden y transiciones */
  function abrirPanel(html) { $('#panel').innerHTML = html; $('#panel').classList.add('abierto'); $('#velo').classList.add('abierto'); }
  function cerrarPanel() { $('#panel').classList.remove('abierto'); $('#velo').classList.remove('abierto'); }
  $('#velo').onclick = cerrarPanel;

  function verOrden(id) {
    abrirPanel('Cargando…');
    Promise.all([api('GET', 'Task/' + id), api('GET', 'Provenance?target=Task/' + id + '&_count=200'), orgs()]).then(function (r) {
      var t = r[0], prov = lista(r[1]), est = estadoDe(t), leg = legado(t);
      var tm = ext(t, 'pim-tipo-mantenimiento'), mf = ext(t, 'pim-modo-falla');
      var h = '<div class="acciones" style="justify-content:space-between;margin:0 0 8px"><h2 style="margin:0;color:var(--az)">Orden ' + esc(t.identifier[0].value) + '</h2><button class="b sec" id="cerrar">Cerrar</button></div>'
        + (leg ? '<div class="aviso info">Reflejada de iProy (código ' + esc((t.identifier[1] || {}).value || '—') + '). Se gestiona en iProy; acá se ve en solo lectura y se actualiza sola cada 5 minutos.</div>' : '')
        + '<dl><dt>Estado</dt><dd><span class="et">' + esc(est) + '</span> <span class="mini">(' + esc(t.status) + ')</span>' + (t.statusReason ? '<div class="mini">Motivo: ' + esc(t.statusReason.text) + '</div>' : '') + '</dd>'
        + '<dt>Prioridad</dt><dd><span class="et ' + esc(t.priority) + '">' + esc(PRIO[t.priority] || t.priority) + '</span></dd>'
        + '<dt>Categoría</dt><dd>' + esc((t.code && (t.code.text || t.code.coding[0].code)) || '—') + '</dd>'
        + '<dt>Activo</dt><dd>' + esc(nombre(t.focus)) + '</dd><dt>Lugar</dt><dd>' + esc(nombre(t.location)) + '</dd>'
        + '<dt>Responsable</dt><dd>' + esc(nombre(t.owner)) + '</dd>'
        + '<dt>Tipo</dt><dd>' + esc(tm ? tm.valueCoding.code : '—') + '</dd><dt>Modo de falla</dt><dd>' + esc(mf ? mf.valueCoding.code : '—') + '</dd>'
        + '<dt>Alta</dt><dd>' + fecha(t.authoredOn) + '</dd><dt>Ejecución</dt><dd>' + (t.executionPeriod ? fecha(t.executionPeriod.start) + ' → ' + fecha(t.executionPeriod.end) : '—') + '</dd>'
        + '<dt>Solicitud</dt><dd>' + esc(t.basedOn ? t.basedOn[0].reference : '—') + '</dd><dt>Versión</dt><dd>' + esc(t.meta.versionId) + ' · ' + fecha(t.meta.lastUpdated) + '</dd></dl>'
        + (t.description ? '<div class="nota">' + esc(t.description) + '</div>' : '')
        + ((t.note || []).length ? '<h2 style="margin-top:14px">Notas</h2>' + t.note.map(function (n) { return '<div class="nota">' + esc(n.text) + '<div class="mini">' + esc(n.authorString) + ' · ' + fecha(n.time) + '</div></div>'; }).join('') : '');
      if (!leg) {
        var sig = TRANS[est] || [];
        h += '<h2 style="margin-top:16px">Cambiar estado</h2>' + (sig.length ? '<div class="acciones">' + sig.map(function (s) {
          return '<button class="b ' + (s === 'anulada' || s === 'rechazada' ? 'peligro' : 'sec') + '" data-s="' + s + '">→ ' + esc(s) + '</button>'; }).join('') + '</div>' : '<div class="mini">Estado terminal.</div>')
          + '<div id="frmT" class="oculto" style="margin-top:12px"><div class="grid2">'
          + '<div><label>Responsable</label><select id="tR"><option value="">(sin cambio)</option>' + ORGS.filter(function (o) { return o.type && o.type[0].coding[0].code !== 'prov'; }).map(function (o) {
            return '<option value="Organization/' + esc(o.id) + '"' + (t.owner && t.owner.reference === 'Organization/' + o.id ? ' selected' : '') + '>' + esc(o.name) + '</option>'; }).join('') + '</select></div>'
          + '<div><label>Prioridad</label><select id="tP"><option value="">(sin cambio)</option>' + Object.keys(PRIO).map(function (p) { return '<option value="' + p + '">' + PRIO[p] + '</option>'; }).join('') + '</select></div>'
          + '<div><label>Tipo de mantenimiento</label><select id="tT"><option value="">(sin cambio)</option>' + TIPOS.map(function (x) { return '<option' + (tm && tm.valueCoding.code === x ? ' selected' : '') + '>' + x + '</option>'; }).join('') + '</select></div>'
          + '<div><label>Modo de falla (ISO 14224)</label><input id="tF" maxlength="3" placeholder="p. ej. ELP, FTS, VIB" value="' + esc(mf ? mf.valueCoding.code : '') + '"></div></div>'
          + '<label style="margin-top:10px">Motivo <span id="tMo" class="mini"></span></label><input id="tM" maxlength="255">'
          + '<label style="margin-top:10px">Nota de trabajo (opcional)</label><textarea id="tN" maxlength="1000"></textarea>'
          + '<div class="acciones"><button class="b" id="tOk">Confirmar</button><button class="b sec" id="tNo">Cancelar</button></div><div id="tMsg"></div></div>';
      }
      h += '<h2 style="margin-top:16px">Trazabilidad (Provenance)</h2><table><thead><tr><th>v</th><th>Cuándo</th><th>Quién</th><th>Motivo</th><th>Huella</th></tr></thead><tbody>'
        + prov.map(function (p) {
          var v = p.target[0].reference.split('/').pop();
          var hu = p.signature ? atob(p.signature[0].data).split('').map(function (c) { return ('0' + c.charCodeAt(0).toString(16)).slice(-2); }).join('').slice(0, 12) : '';
          return '<tr><td>' + esc(v) + '</td><td class="mini">' + fecha(p.recorded) + '</td><td>' + esc(p.agent[0].who.identifier.value) + '</td><td>' + esc(p.reason ? p.reason[0].text : '') + '</td><td><code>' + hu + '…</code></td></tr>';
        }).join('') + '</tbody></table>';
      abrirPanel(h);
      $('#cerrar').onclick = cerrarPanel;
      var destino = null;
      [].forEach.call(document.querySelectorAll('#panel [data-s]'), function (b) {
        b.onclick = function () {
          destino = b.getAttribute('data-s');
          $('#frmT').classList.remove('oculto');
          $('#tMo').textContent = CON_MOTIVO.indexOf(destino) >= 0 ? '(obligatorio para «' + destino + '»)' : '(opcional)';
          $('#tOk').textContent = 'Pasar a «' + destino + '»';
        };
      });
      if ($('#tNo')) $('#tNo').onclick = function () { $('#frmT').classList.add('oculto'); };
      if ($('#tOk')) $('#tOk').onclick = function () {
        var p = [{ name: 'estado', valueCode: destino }];
        if ($('#tR').value) p.push({ name: 'responsable', valueReference: { reference: $('#tR').value } });
        if ($('#tP').value) p.push({ name: 'prioridad', valueCode: $('#tP').value });
        if ($('#tT').value) p.push({ name: 'tipoMantenimiento', valueCode: $('#tT').value });
        if ($('#tF').value.trim()) p.push({ name: 'modoFalla', valueCode: $('#tF').value.trim().toUpperCase() });
        if ($('#tM').value.trim()) p.push({ name: 'motivo', valueString: $('#tM').value.trim() });
        if ($('#tN').value.trim()) p.push({ name: 'nota', valueString: $('#tN').value.trim() });
        $('#tOk').disabled = true;
        api('POST', 'Task/' + t.id + '/$transicion', { resourceType: 'Parameters', parameter: p }, t.meta.versionId)
          .then(function () { verOrden(t.id); })
          .catch(function (e) { $('#tOk').disabled = false; aviso($('#tMsg'), 'mal', e.http === 412 ? 'Otro usuario cambió la orden. Cerrá y volvé a abrirla.' : e.message); });
      };
    }).catch(function (e) { abrirPanel('<div class="aviso mal">' + esc(e.message) + '</div>'); });
  }

  /* ---------------------------------------------------------------- nueva solicitud (PIM-SOL) */
  function buscador(cont, alElegir) {
    cont.innerHTML = '<div class="grid2"><div><label>Buscar en</label><select class="bT"><option value="Device">Activos / equipos</option><option value="Location">Locales / servicios</option></select></div>'
      + '<div><label>Nombre (mín. 2 letras)</label><input class="bQ" placeholder="p. ej. UPS, Quirófano, Respirador"></div></div><div class="bL"></div>';
    var tmr;
    function buscar() {
      var q = $('.bQ', cont).value.trim(), tipo = $('.bT', cont).value; if (q.length < 2) { $('.bL', cont).innerHTML = ''; return; }
      api('GET', tipo + '?' + (tipo === 'Device' ? 'device-name' : 'name') + ':contains=' + encodeURIComponent(q) + '&_count=15').then(function (b) {
        var l = lista(b);
        $('.bL', cont).innerHTML = l.length ? '<table><tbody>' + l.map(function (r) {
          var n = tipo === 'Device' ? r.deviceName[0].name : r.name, iru = tipo === 'Device' ? ext(r, 'pim-iru') : null;
          var banda = iru ? iru.extension.filter(function (x) { return x.url === 'banda'; })[0].valueCode : '';
          return '<tr class="clic" data-r="' + tipo + '/' + esc(r.id) + '" data-n="' + esc(n) + '"><td>' + esc(n) + '</td><td class="mini">' + esc(r.identifier[0].value) + '</td><td class="mini">' + esc(r.location ? nombre(r.location) : '') + '</td><td>' + (banda ? '<span class="et ' + banda + '">IRU ' + banda + '</span>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="mini">Sin resultados.</div>';
      });
    }
    $('.bQ', cont).oninput = function () { clearTimeout(tmr); tmr = setTimeout(buscar, 300); };
    $('.bT', cont).onchange = buscar;
    $('.bL', cont).onclick = function (e) { var tr = e.target.closest('tr.clic'); if (tr) alElegir(tr.getAttribute('data-r'), tr.getAttribute('data-n')); };
  }
  vistas.solicitud = function () {
    main.innerHTML = '<div class="tarjeta"><h2>Nueva solicitud de servicio</h2><p class="mini">La solicitud crea su orden de trabajo. Si no elegís prioridad y el activo tiene IRU, la prioridad sale de su banda de riesgo. No cargues datos de pacientes.</p>'
      + '<label>1 · ¿Sobre qué?</label><div id="sB"></div><div id="sE" class="aviso info oculto"></div>'
      + '<div class="grid2" style="margin-top:12px"><div><label>2 · Categoría</label><input id="sC" list="sistemas" placeholder="p. ej. ELE-UPS o «Pérdida de agua»"><datalist id="sistemas"></datalist></div>'
      + '<div><label>Estado del activo</label><select id="sD"><option value="">(no informado)</option><option>operativo-con-falla</option><option>degradado</option><option>fuera-de-servicio</option></select></div>'
      + '<div><label>Prioridad</label><select id="sP"><option value="">Automática (IRU)</option>' + Object.keys(PRIO).map(function (p) { return '<option value="' + p + '">' + PRIO[p] + '</option>'; }).join('') + '</select></div>'
      + '<div><label>Solicitado por (servicio o rol)</label><input id="sS" maxlength="160" placeholder="p. ej. Jefatura de Quirófano"></div></div>'
      + '<label style="margin-top:10px">3 · Descripción del problema</label><textarea id="sT" maxlength="5000"></textarea>'
      + '<div class="acciones"><button class="b" id="sOk">Enviar solicitud</button></div><div id="sM"></div></div>';
    var sujeto = null;
    buscador($('#sB'), function (r, n) { sujeto = r; $('#sE').classList.remove('oculto'); $('#sE').textContent = 'Elegido: ' + n + ' (' + r.split('/')[0] + ')'; });
    api('GET', 'CodeSystem/pim-sistema').then(function (c) { $('#sistemas').innerHTML = c.concept.map(function (x) { return '<option value="' + esc(x.code) + '">' + esc(x.display) + '</option>'; }).join(''); }).catch(function () {});
    $('#sOk').onclick = function () {
      if (!sujeto) return aviso($('#sM'), 'mal', 'Elegí el activo o el local.');
      var c = $('#sC').value.trim(), d = $('#sT').value.trim();
      if (!c || !d) return aviso($('#sM'), 'mal', 'Completá la categoría y la descripción.');
      var sr = { resourceType: 'ServiceRequest', status: 'active', intent: 'order', subject: { reference: sujeto },
        code: { coding: [{ system: CS + 'pim-categoria', code: c.replace(/\s+/g, '-').toUpperCase().slice(0, 40) }], text: c }, note: [{ text: d }] };
      if ($('#sP').value) sr.priority = $('#sP').value;
      if ($('#sS').value.trim()) sr.requester = { display: $('#sS').value.trim() };
      if ($('#sD').value) sr.extension = [{ url: SD + 'pim-estado-declarado', valueCode: $('#sD').value }];
      $('#sOk').disabled = true;
      api('POST', 'ServiceRequest', sr).then(function (r) {
        $('#sOk').disabled = false;
        $('#sM').innerHTML = '<div class="aviso ok">Solicitud ' + esc(r.identifier[0].value) + ' creada con prioridad «' + esc(PRIO[r.priority] || r.priority) + '». '
          + (r._task ? '<a href="#" id="verT">Ver la orden generada</a>' : '') + '</div>';
        if (r._task) $('#verT').onclick = function (e) { e.preventDefault(); verOrden(idDe(r._task)); };
      }).catch(function (e) { $('#sOk').disabled = false; aviso($('#sM'), 'mal', e.message); });
    };
  };

  /* ---------------------------------------------------------------- inventario (PIM-INV) */
  vistas.inventario = function (sub) {
    sub = sub || 'Device';
    main.innerHTML = '<div class="tarjeta"><div class="acciones" style="margin:0 0 12px">'
      + [['Device', 'Activos y equipos'], ['Location', 'Locales'], ['Organization', 'Organizaciones']].map(function (x) { return '<button class="b ' + (x[0] === sub ? '' : 'sec') + '" data-sub="' + x[0] + '">' + x[1] + '</button>'; }).join('')
      + '<span style="flex:1"></span><button class="b sec" id="nuevo">+ Nuevo</button></div>'
      + '<div class="filtros"><div class="campo"><label>Buscar por nombre</label><input id="iQ"></div><button class="b" id="iB">Buscar</button></div><div id="iL"></div><div id="iF"></div></div>';
    [].forEach.call(document.querySelectorAll('[data-sub]'), function (b) { b.onclick = function () { vistas.inventario(b.getAttribute('data-sub')); }; });
    function cargar() {
      var q = $('#iQ').value.trim(), p = sub === 'Device' ? 'device-name' : 'name';
      $('#iL').innerHTML = 'Cargando…';
      api('GET', sub + '?_count=100' + (q ? '&' + p + ':contains=' + encodeURIComponent(q) : '')).then(function (b) {
        var l = lista(b);
        if (!l.length) return aviso($('#iL'), 'info', 'Sin resultados.');
        if (sub === 'Device') $('#iL').innerHTML = '<div class="mini">' + b.total + ' en total</div><table><thead><tr><th>Código</th><th>Nombre</th><th>Tipo</th><th>Local</th><th>NFPA</th><th>IRU</th><th></th></tr></thead><tbody>' + l.map(function (d) {
          var i = ext(d, 'pim-iru'), c = ext(d, 'pim-categoria-riesgo'), v = function (k) { return i ? (i.extension.filter(function (x) { return x.url === k; })[0] || {}) : {}; };
          return '<tr><td>' + esc(d.identifier[0].value) + '</td><td>' + esc(d.deviceName[0].name) + '</td><td class="mini">' + esc(d.type ? (d.type.text || d.type.coding[0].display || d.type.coding[0].code) : '') + '</td><td>' + esc(nombre(d.location)) + '</td>'
            + '<td>' + esc(c ? c.valueCoding.code : '') + '</td><td>' + (i ? '<span class="et ' + v('banda').valueCode + '">' + v('banda').valueCode + ' · ' + v('total').valueInteger + '</span>' : '') + '</td><td>' + (legado(d) ? '<span class="et leg">iProy</span>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>';
        else if (sub === 'Location') $('#iL').innerHTML = '<div class="mini">' + b.total + ' en total</div><table><thead><tr><th>Código</th><th>Nombre</th><th>Tipo</th><th>Grupo</th><th>Estado</th><th></th></tr></thead><tbody>' + l.map(function (x) {
          var g = ext(x, 'pim-grupo-uso-medico');
          return '<tr><td>' + esc(x.identifier[0].value) + '</td><td>' + esc(x.name) + '</td><td>' + esc(x.physicalType.coding[0].display || '') + '</td><td>' + esc(g ? g.valueCoding.code : '') + '</td><td>' + esc(x.status) + '</td><td>' + (legado(x) ? '<span class="et leg">iProy</span>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>';
        else $('#iL').innerHTML = '<table><thead><tr><th>Código</th><th>Nombre</th><th>Tipo</th><th></th></tr></thead><tbody>' + l.map(function (o) {
          return '<tr><td>' + esc(o.identifier[0].value) + '</td><td>' + esc(o.name) + '</td><td>' + esc(o.type[0].coding[0].display) + '</td><td>' + (legado(o) ? '<span class="et leg">iProy</span>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>';
      }).catch(function (e) { aviso($('#iL'), 'mal', e.message); });
    }
    $('#iB').onclick = cargar; $('#iQ').onkeydown = function (e) { if (e.key === 'Enter') cargar(); };
    $('#nuevo').onclick = function () { formAlta(sub); };
    cargar();
  };
  function formAlta(tipo) {
    var f = $('#iF'), h = '<h2 style="margin-top:16px">Alta de ' + { Device: 'activo', Location: 'local', Organization: 'organización' }[tipo] + '</h2><div class="grid2">'
      + '<div><label>Código (único)</label><input id="aC" maxlength="60"></div><div><label>Nombre</label><input id="aN" maxlength="160"></div>';
    if (tipo === 'Organization') h += '<div><label>Tipo</label><select id="aT"><option value="bus">Empresa contratista</option><option value="dept">Área interna</option></select></div>';
    if (tipo === 'Location') h += '<div><label>Tipo físico</label><select id="aT"><option value="area">Área</option><option value="bu">Edificio</option><option value="lvl">Nivel</option><option value="ro">Local / sala</option></select></div>'
      + '<div><label>Grupo de uso médico</label><select id="aG"><option value="">—</option><option>0</option><option>1</option><option>2a</option><option>2b</option></select></div>';
    if (tipo === 'Device') h += '<div><label>Clase</label><select id="aK"><option value="pim-sistema">Sistema de instalación</option><option value="pim-activo">Activo / componente</option><option value="pim-equipo-medico">Equipo médico</option></select></div>'
      + '<div><label>Sistema (Anexo B) o tipo</label><input id="aT" list="sis2" placeholder="p. ej. GAS-O2"><datalist id="sis2"></datalist></div>'
      + '<div><label>Local</label><select id="aL"><option value="">—</option></select></div>'
      + '<div><label>Categoría NFPA 99</label><select id="aR"><option value="">—</option><option value="1">1 · lesión grave</option><option value="2">2 · lesión menor</option><option value="3">3 · molestia</option><option value="4">4 · sin impacto</option></select></div>'
      + '<div><label>Redundancia</label><select id="aRe"><option value="">—</option><option>N</option><option>N+1</option><option>2N</option></select></div>'
      + '<div><label>IRU F (función 1–10)</label><input id="aF" type="number" min="1" max="10"></div><div><label>IRU M (mantenimiento 1–5)</label><input id="aM" type="number" min="1" max="5"></div>'
      + '<div><label>IRU A (historial −2…+2)</label><input id="aA" type="number" min="-2" max="2" value="0"></div>'
      + '<div><label>Punto de medición (opcional)</label><input id="aP" placeholder="p. ej. PRES-LINEA"></div><div><label>Unidad UCUM</label><input id="aU" placeholder="p. ej. kPa, Cel, %"></div>'
      + '<div><label>Mínimo</label><input id="aMi" type="number" step="any"></div><div><label>Máximo</label><input id="aMa" type="number" step="any"></div>';
    h += '</div><div class="acciones"><button class="b" id="aOk">Guardar</button></div><div id="aMsg"></div>';
    f.innerHTML = h;
    if (tipo === 'Device') {
      api('GET', 'CodeSystem/pim-sistema').then(function (c) { $('#sis2').innerHTML = c.concept.map(function (x) { return '<option value="' + esc(x.code) + '">' + esc(x.display) + '</option>'; }).join(''); });
      api('GET', 'Location?_count=200').then(function (b) { $('#aL').innerHTML += lista(b).map(function (l) { return '<option value="' + esc(l.id) + '">' + esc(l.name) + '</option>'; }).join(''); });
    }
    $('#aOk').onclick = function () {
      var c = $('#aC').value.trim(), n = $('#aN').value.trim(), r;
      if (!c || !n) return aviso($('#aMsg'), 'mal', 'Código y nombre son obligatorios.');
      if (tipo === 'Organization') r = { resourceType: 'Organization', identifier: [{ system: NS + '/sid/empresa', value: c }], name: n, active: true, type: [{ coding: [{ code: $('#aT').value }] }] };
      if (tipo === 'Location') { r = { resourceType: 'Location', identifier: [{ system: NS + '/sid/local', value: c }], name: n, status: 'active', physicalType: { coding: [{ code: $('#aT').value }] } };
        if ($('#aG').value) r.extension = [{ url: SD + 'pim-grupo-uso-medico', valueCoding: { code: $('#aG').value } }]; }
      if (tipo === 'Device') {
        var e = [], iru = [];
        r = { resourceType: 'Device', meta: { profile: [SD + $('#aK').value] }, identifier: [{ system: NS + '/sid/activo', value: c }], deviceName: [{ name: n, type: 'user-friendly-name' }], status: 'active' };
        if ($('#aT').value.trim()) r.type = { coding: [{ system: CS + ($('#aK').value === 'pim-equipo-medico' ? 'clase-equipo' : 'pim-sistema'), code: $('#aT').value.trim() }] };
        if ($('#aL').value) r.location = { reference: 'Location/' + $('#aL').value };
        if ($('#aR').value) e.push({ url: SD + 'pim-categoria-riesgo', valueCoding: { code: $('#aR').value } });
        if ($('#aRe').value) e.push({ url: SD + 'pim-redundancia', valueCode: $('#aRe').value });
        ['F', 'M', 'A'].forEach(function (k) { var v = $('#a' + k).value; if (v !== '') iru.push({ url: k, valueInteger: parseInt(v, 10) }); });
        if (iru.length) e.push({ url: SD + 'pim-iru', extension: iru });
        if ($('#aP').value.trim()) {
          var pt = [{ url: 'punto', valueCode: $('#aP').value.trim().toUpperCase() }, { url: 'unidad', valueCode: $('#aU').value.trim() }];
          if ($('#aMi').value !== '') pt.push({ url: 'minimo', valueDecimal: parseFloat($('#aMi').value) });
          if ($('#aMa').value !== '') pt.push({ url: 'maximo', valueDecimal: parseFloat($('#aMa').value) });
          e.push({ url: SD + 'pim-punto-medicion', extension: pt });
        }
        if (e.length) r.extension = e;
      }
      $('#aOk').disabled = true;
      api('POST', tipo, r).then(function () { ORGS = null; vistas.inventario(tipo); })
        .catch(function (x) { $('#aOk').disabled = false; aviso($('#aMsg'), 'mal', x.message); });
    };
  }

  /* ---------------------------------------------------------------- telemetría (PIM-TEL) */
  vistas.telemetria = function () {
    main.innerHTML = '<div class="tarjeta"><h2>Cargar una medición</h2><p class="mini">Fuera del rango del punto, el servidor crea solicitud y orden automáticamente (sin duplicar una orden abierta del mismo punto).</p>'
      + '<label>Activo</label><div id="tB"></div><div id="tE" class="aviso info oculto"></div>'
      + '<div class="grid2" style="margin-top:10px"><div><label>Punto</label><input id="mP" placeholder="p. ej. TEMP-AMB"></div><div><label>Valor</label><input id="mV" type="number" step="any"></div>'
      + '<div><label>Unidad UCUM</label><input id="mU" placeholder="Cel, kPa, %"></div></div>'
      + '<div class="acciones"><button class="b" id="mOk">Enviar medición</button></div><div id="mM"></div></div>'
      + '<div class="tarjeta"><h2>Últimas mediciones</h2><div id="mL"></div></div>';
    var dev = null;
    buscador($('#tB'), function (r, n) { if (r.indexOf('Device/') !== 0) return; dev = r; $('#tE').classList.remove('oculto'); $('#tE').textContent = 'Activo: ' + n; });
    function ult() {
      api('GET', 'Observation?_count=20').then(function (b) {
        var l = lista(b);
        $('#mL').innerHTML = l.length ? '<table><thead><tr><th>Cuándo</th><th>Activo</th><th>Punto</th><th>Valor</th><th>Rango</th><th>Resultado</th></tr></thead><tbody>' + l.map(function (o) {
          var i = o.interpretation ? o.interpretation[0].coding[0].code : '', rr = o.referenceRange ? o.referenceRange[0] : null, g = ext(o, 'pim-orden-generada');
          return '<tr' + (g ? ' class="clic" data-id="' + esc(idDe(g.valueReference.reference)) + '"' : '') + '><td class="mini">' + fecha(o.effectiveDateTime) + '</td><td>' + esc(nombre(o.device)) + '</td><td>' + esc(o.code.coding[0].code) + '</td>'
            + '<td><b>' + esc(o.valueQuantity.value) + '</b> ' + esc(o.valueQuantity.unit) + '</td><td class="mini">' + (rr ? esc((rr.low ? rr.low.value : '—') + ' a ' + (rr.high ? rr.high.value : '—')) : '') + '</td>'
            + '<td>' + (i === 'N' ? '<span class="et D">normal</span>' : i ? '<span class="et stat">' + (i === 'H' ? 'alto' : 'bajo') + '</span>' : '') + (g ? ' <span class="mini">→ orden</span>' : '') + '</td></tr>';
        }).join('') + '</tbody></table>' : '<div class="mini">Todavía no hay mediciones.</div>';
      }).catch(function (e) { aviso($('#mL'), 'mal', e.message); });
    }
    enlazarFilas($('#mL'));
    $('#mOk').onclick = function () {
      if (!dev) return aviso($('#mM'), 'mal', 'Elegí un activo (buscá en «Activos / equipos»).');
      var o = { resourceType: 'Observation', status: 'final', device: { reference: dev }, code: { coding: [{ system: CS + 'pim-punto', code: $('#mP').value.trim().toUpperCase() }] },
        effectiveDateTime: new Date().toISOString(), valueQuantity: { value: parseFloat($('#mV').value), unit: $('#mU').value.trim(), system: 'http://unitsofmeasure.org', code: $('#mU').value.trim() } };
      api('POST', 'Observation', o).then(function (r) {
        var g = ext(r, 'pim-orden-generada'), i = r.interpretation ? r.interpretation[0].coding[0].code : '';
        aviso($('#mM'), g ? 'mal' : 'ok', g ? 'Fuera de rango: quedó asociada a una orden de trabajo.' : (i ? 'Medición dentro del rango.' : 'Medición guardada (el punto no tiene rango configurado).'));
        ult();
      }).catch(function (e) { aviso($('#mM'), 'mal', e.message); });
    };
    ult();
  };

  /* ---------------------------------------------------------------- acerca de */
  vistas.acerca = function () {
    api('GET', 'metadata').then(function (m) {
      var conf = (m.extension || [])[0], perf = conf ? conf.extension.filter(function (x) { return x.url === 'perfilIntegracion'; }).map(function (x) { return x.valueCode; }) : [];
      main.innerHTML = '<div class="tarjeta"><h2>' + esc(m.title) + '</h2><p>' + esc(m.description) + '</p><dl>'
        + '<dt>Implementación</dt><dd>' + esc(m.implementation.description) + '</dd><dt>URL de la API</dt><dd><code>' + esc(m.implementation.url) + '</code></dd>'
        + '<dt>FHIR</dt><dd>' + esc(m.fhirVersion) + '</dd><dt>Nivel</dt><dd>Básico (completo)</dd><dt>Perfiles de integración</dt><dd>' + perf.map(function (p) { return '<span class="et">' + esc(p) + '</span>'; }).join(' ') + '</dd>'
        + '<dt>Recursos</dt><dd>' + m.rest[0].resource.map(function (r) { return esc(r.type); }).join(', ') + '</dd><dt>Seguridad</dt><dd class="mini">' + esc(m.rest[0].documentation) + '</dd></dl>'
        + '<div class="aviso info">Esta Consola es un cliente más del Servidor PIM (cliente «consola-local», rol Gestor). Los datos de iProy se reflejan solos cada 5 minutos y se ven en solo lectura; iProy no se modifica.</div></div>';
    }).catch(function (e) { aviso(main, 'mal', e.message); });
  };

  /* ---------------------------------------------------------------- arranque */
  api('GET', 'metadata').then(function (m) {
    $('#chipNodo').textContent = m.implementation.description;
    $('#chipVer').textContent = 'PIM v0.9 · API ' + m.version;
  }).catch(function (e) { $('#chipNodo').textContent = 'sin conexión con la API'; aviso(main, 'mal', e.message); });
  ir('tablero');
})();
