// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo migracion · solapa «Migración» de Configuración: origen (hoja de cálculo, hoja publicada o base MySQL), destino,
// unión de columnas, equivalencias con el conteo de cada valor, simulación y carga.
(() => {
  const { h, fecha, aviso, tabla, api, kpis, icono } = Nucleo;
  const $ = (s, r = document) => r.querySelector(s); const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const PARTE = 300000;   // caracteres por pedido: el núcleo acepta cuerpos de hasta 2 MB
  const E = { sub: 'traer', origen: { tipo: 'csv', texto: '', nombre: '' }, destino: 'ordenes', an: null, mapa: null, res: null };
  const num = (n) => Number(n || 0).toLocaleString('es-AR');
  const ORIGENES = [['csv', 'Hoja de cálculo (archivo CSV)', 'En la hoja: Archivo › Descargar › Valores separados por comas (.csv). También sirve el CSV que exporta Excel o LibreOffice.'],
    ['url', 'Hoja publicada en la web', 'En la hoja de Google: Archivo › Compartir › Publicar en la web › elegí la pestaña y «Valores separados por comas». Pegá acá la dirección que te da.'],
    ['mysql', 'Base de datos MySQL o MariaDB', 'El nodo se conecta en modo de solo lectura y trae el resultado de una tabla o de una consulta SELECT. La clave se usa en el momento y no se guarda.']];

  Nucleo.configuracion({ id: 'migracion', titulo: 'Migración', render: async (c) => {
    const S = await api('GET', 'm/migracion/estado');
    c.innerHTML = `<div class="subp" id="mgS">${[['traer', 'Traer datos'], ['equivalencias', 'Equivalencias'], ['historial', `Historial ${S.corridas.length}`], ['ajustes', 'Ajustes']].map(([k, t]) => `<button data-s="${k}" class="${E.sub === k ? 'activo' : ''}">${t}</button>`).join('')}</div><div id="mgC"></div>`;
    $$('#mgS button', c).forEach((b) => b.onclick = () => { E.sub = b.dataset.s; Nucleo.ir('configuracion'); });
    const z = $('#mgC', c);
    const opPim = (lista, v) => { const ops = { estado: S.pim.estados.map((e) => [e, e]), tipo_mantenimiento: S.pim.tipos.map((e) => [e, e]), prioridad: S.pim.prioridades.map((e) => [e, e]), categoria: Object.entries(S.pim.sistemas).map(([k, n]) => [k, `${k} · ${n}`]), riesgo: [['1', '1 · la falla puede causar muerte o lesión grave'], ['2', '2 · lesión menor'], ['3', '3 · molestia'], ['4', '4 · sin efecto sobre el paciente']] }[lista];
      return `<option value="">— Sin equivalente —</option>${ops.map(([k, t]) => `<option value="${h(k)}" ${k === v ? 'selected' : ''}>${h(t)}</option>`).join('')}`; };

    if (E.sub === 'traer') {
      const o = E.origen;
      z.innerHTML = `<div class="tarjeta"><h2>1 · De dónde vienen los datos</h2><div class="kpis" id="mgO"></div><p class="mini" id="mgAy"></p><div id="mgF"></div></div>
        <div class="tarjeta"><h2>2 · Qué son</h2><div class="fgrid"><div class="fcampo"><label>Destino en el nodo</label><select id="mgD">${Object.entries(S.destinos).map(([k, d]) => `<option value="${k}" ${E.destino === k ? 'selected' : ''}>${h(d.nombre)}</option>`).join('')}</select>
          <small class="fayuda">Orden recomendado: servicios, empresas, equipos y por último órdenes.</small></div></div>
          <div class="acciones"><button class="b" id="mgLeer">Leer el origen</button></div><div id="mgM1"></div></div><div id="mgR"></div>`;
      const pintaO = () => { $('#mgO', z).innerHTML = kpis(ORIGENES.map(([k, t]) => ({ t, n: '', k, icono: k === 'mysql' ? 'sistema' : k === 'url' ? 'colmena' : 'bajar', color: '#1565c0', activo: o.tipo === k }))); $('#mgAy', z).textContent = ORIGENES.find((x) => x[0] === o.tipo)[2];
        $$('#mgO .kpi', z).forEach((x) => x.onclick = () => { E.origen = { tipo: x.dataset.k, texto: '', nombre: '' }; E.an = null; E.mapa = null; E.res = null; Nucleo.ir('configuracion'); });
        $('#mgF', z).innerHTML = o.tipo === 'csv' ? `<input type="file" id="mgArc" accept=".csv,text/csv,text/plain"> <span class="mini">${o.nombre ? `Cargado: ${h(o.nombre)} (${num(o.car || o.texto.length)} caracteres)` : ''}</span><details class="desp"><summary><span class="mas">＋</span>O pegar el contenido</summary><div class="desp-c"><textarea id="mgTx" rows="5" placeholder="numero;fecha;estado;servicio;detalle">${o.nombre ? '' : h(o.texto.slice(0, 20000))}</textarea></div></details>`
          : o.tipo === 'url' ? `<label>Dirección de la hoja publicada como CSV</label><input id="mgUrl" type="url" placeholder="https://docs.google.com/spreadsheets/d/e/…/pub?output=csv" value="${h(o.url || '')}"><small class="fayuda">Sitios permitidos: ${h(S.hosts_url.join(', '))}</small>`
          : `<div class="fgrid"><div class="fcampo"><label>Servidor</label><input id="myH" value="${h(o.host || '')}" placeholder="127.0.0.1 o nombre del servidor"></div><div class="fcampo"><label>Puerto</label><input id="myP" inputmode="numeric" value="${h(o.puerto || 3306)}"></div>
              <div class="fcampo"><label>Base</label><input id="myB" value="${h(o.base || '')}"></div><div class="fcampo"><label>Usuario (con permiso de lectura)</label><input id="myU" value="${h(o.usuario || '')}" autocomplete="off"></div>
              <div class="fcampo"><label>Clave</label><input id="myC" type="password" autocomplete="new-password" value="${h(o.clave || '')}"><small class="fayuda">No se guarda: queda en esta pantalla hasta que salgas.</small></div><div class="fcampo"><label>Tabla o vista</label><input id="myT" value="${h(o.tabla || '')}" placeholder="ej.: ordenes"></div>
              <div class="fcampo" data-todo="1"><label>O una consulta SELECT (opcional; reemplaza a la tabla)</label><textarea id="myQ" rows="3" placeholder="SELECT id, fecha, estado, servicio, detalle FROM ordenes WHERE fecha >= '2025-01-01'">${h(o.consulta || '')}</textarea></div></div>`;
        const a = $('#mgArc', z); if (a) a.onchange = async () => { const f = a.files[0]; if (!f) return; const buf = new Uint8Array(await f.arrayBuffer()); let t; try { t = new TextDecoder('utf-8', { fatal: true }).decode(buf); } catch (x) { t = new TextDecoder('windows-1252').decode(buf); }
          delete o.subida; delete o.car; E.an = null; E.mapa = null;
          if (t.length > PARTE) {   // archivo grande: va al nodo en partes y después se nombra por su id
            try { let id = ''; for (let i = 0; i < t.length; i += PARTE) { aviso($('#mgM1', z), 'info', `Subiendo el archivo al nodo… ${Math.round(i * 100 / t.length)} %`); id = (await api('POST', 'm/migracion/subir', { id, texto: t.slice(i, i + PARTE) })).id; }
              o.subida = id; o.car = t.length; o.texto = ''; o.nombre = f.name; } catch (x) { o.texto = ''; o.nombre = ''; pintaO(); aviso($('#mgM1', z), 'mal', x.message); return; }
          } else { o.texto = t; o.nombre = f.name; }
          pintaO(); }; };
      const juntar = () => { if (o.tipo === 'csv') { const t = $('#mgTx', z)?.value; if (t && t.trim()) { o.texto = t; o.nombre = ''; delete o.subida; delete o.car; } } else if (o.tipo === 'url') o.url = $('#mgUrl', z).value.trim();
        else Object.assign(o, { host: $('#myH', z).value.trim(), puerto: $('#myP', z).value.trim(), base: $('#myB', z).value.trim(), usuario: $('#myU', z).value, clave: $('#myC', z).value, tabla: $('#myT', z).value.trim(), consulta: $('#myQ', z).value.trim() }); return o; };
      pintaO();
      $('#mgD', z).onchange = (e) => { E.destino = e.target.value; E.an = null; E.mapa = null; E.res = null; $('#mgR', z).innerHTML = ''; };
      const paso3 = () => { const A = E.an; if (!A) return; const campos = S.destinos[E.destino].campos; E.mapa = E.mapa || { ...A.mapa_propuesto };
        $('#mgR', z).innerHTML = `<div class="tarjeta"><h2>3 · Unir columnas</h2><p class="mini">El origen trae <b>${num(A.filas)}</b> filas y ${A.columnas.length} columnas. Para cada dato del nodo elegí de qué columna sale. Lo que quede sin columna no se carga.</p>
            <div class="fgrid">${Object.entries(campos).map(([k, [et, req]]) => `<div class="fcampo"><label>${h(et)}${req ? ' <b class="req">*</b>' : ''}</label><select class="mgMap" data-k="${k}"><option value="">— No viene —</option>${A.columnas.map((col) => `<option ${E.mapa[k] === col ? 'selected' : ''}>${h(col)}</option>`).join('')}</select></div>`).join('')}</div>
            <details class="desp"><summary><span class="mas">＋</span>Ver las primeras filas del origen</summary><div class="desp-c" style="overflow:auto">${tabla(A.columnas.slice(0, 14).map((col) => ({ t: col, v: (r) => r[col] })), A.muestra)}</div></details>
            <div class="acciones">${E.destino === 'ordenes' ? '<button class="b sec" id="mgAn">Revisar equivalencias</button>' : ''}<button class="b" id="mgSim">Simular la carga</button></div>
            ${E.destino === 'ordenes' ? '<label class="fchk"><input type="checkbox" id="mgCF" checked> Crear los servicios y las empresas que nombren las órdenes y todavía no existan</label>' : ''}
            ${E.destino === 'planes' ? `<label class="fchk"><input type="checkbox" id="mgAg" checked> Un plan por equipo o instalación y frecuencia (sus tareas van como lista de verificación: una orden por visita)</label>
              <label class="fchk"><input type="checkbox" id="mgHa"> Habilitar los planes al cargar (si no, quedan en pausa hasta revisarlos: un plan vencido genera su orden en la siguiente pasada)</label>
              <p class="mini">Los equipos e instalaciones tienen que estar cargados antes. Las tareas sin frecuencia entran en pausa.</p>` : ''}
            ${E.destino === 'tareas_pmp' ? '<p class="mini">Una fila por tarea, como la hoja de cronograma anual. Los equipos e instalaciones tienen que estar cargados antes (Activos). Con «Próxima ejecución», el cronograma de ese año se marca desde esa semana según la frecuencia; el resto se marca después en Preventivos › Cronograma anual. La carga se puede repetir: no duplica tareas.</p>' : ''}<div id="mgM3"></div></div><div id="mgV"></div><div id="mgRes"></div>`;
        $$('.mgMap', z).forEach((s) => s.onchange = () => { E.mapa[s.dataset.k] = s.value; });
        const an = $('#mgAn', z); if (an) an.onclick = async () => { try { aviso($('#mgM3', z), 'info', 'Contando valores…'); const r = await api('POST', 'm/migracion/analizar', { origen: juntar(), destino: E.destino, mapa: E.mapa }); $('#mgM3', z).innerHTML = ''; valores(r.valores); } catch (x) { aviso($('#mgM3', z), 'mal', x.message); } };
        $('#mgSim', z).onclick = () => correr(true); };
      const valores = (V) => { const nombres = S.listas;
        $('#mgV', z).innerHTML = Object.entries(V).map(([lista, l]) => `<div class="tarjeta"><h2>${h(nombres[lista])}</h2><p class="mini">${lista === 'estado' ? 'Para cada estado del origen, a qué estado PIM pasa. «Con fecha de fin» ayuda a decidir: por ejemplo, una orden «en revisión» que ya tiene fecha de fin está terminada.'
            : lista === 'categoria' ? 'Sin equivalente, la orden conserva el texto original como categoría propia del nodo.' : lista === 'prioridad' ? 'Las bandas A, B, C y D pasan por la tabla normativa de PIM si no se elige otra cosa.' : ''}</p>
          ${tabla([{ t: 'Valor en el origen', html: true, v: (x) => x.valor === '' ? '<i class="mini">(vacío)</i>' : h(x.valor) }, { t: 'Filas', v: (x) => num(x.filas) }, ...(lista === 'estado' ? [{ t: 'Con fecha de fin', v: (x) => num(x.con_fin) }, { t: 'Con inicio', v: (x) => num(x.con_inicio) }] : []),
            { t: 'Pasa a (PIM)', html: true, v: (x) => x.valor === '' ? '<span class="mini">—</span>' : `<select class="mgEq" data-l="${lista}" data-o="${h(x.valor)}">${opPim(lista, x.destino || '')}</select>` }, { t: 'Nota', v: (x) => x.nota || '' }], l)}
          <div class="acciones"><button class="b sec" data-gl="${lista}">Guardar estas equivalencias</button></div><div class="mgEm" data-l="${lista}"></div></div>`).join('');
        $$('[data-gl]', z).forEach((b) => b.onclick = async () => { const l = b.dataset.gl; const m = $(`.mgEm[data-l="${l}"]`, z);
          try { const r = await api('PUT', 'm/migracion/equivalencias/' + l, { equivalencias: $$(`.mgEq[data-l="${l}"]`, z).map((s) => ({ etiqueta: s.dataset.o, destino: s.value })) }); S.equivalencias = r.equivalencias; aviso(m, 'ok', `Guardadas ${r.guardadas}.`); } catch (x) { aviso(m, 'mal', x.message); } }); };
      const correr = async (simular) => { const m = $('#mgM3', z);
        try { aviso(m, 'info', simular ? 'Simulando: se valida todo y no se escribe nada…' : 'Cargando…'); const r = await api('POST', 'm/migracion/cargar', { origen: juntar(), destino: E.destino, mapa: E.mapa, simular, crear_faltantes: $('#mgCF', z)?.checked ?? false, opciones: { agrupar: $('#mgAg', z)?.checked ?? true, habilitar: $('#mgHa', z)?.checked ?? false } }); m.innerHTML = ''; E.res = r; resultado(r); }
        catch (x) { aviso(m, 'mal', x.message); } };
      const resultado = (r) => { const cu = r.cuenta; const sinEq = Object.entries(r.sin_equivalencia || {});
        $('#mgRes', z).innerHTML = `<div class="tarjeta"><h2>${r.simulada ? '4 · Simulación' : 'Carga terminada'}</h2><p class="mini">${r.simulada ? 'No se escribió nada. Esto es lo que pasaría:' : `Corrida N.º ${r.corrida}.`}</p>
          ${kpis([{ t: 'Nuevas', n: num(cu.nuevo), icono: 'ok', color: '#2e7d32' }, { t: 'Cambian', n: num(cu.cambio), icono: 'reloj', color: '#1565c0' }, { t: 'Iguales', n: num(cu.igual), sub: 'ya estaban', icono: 'modulo', color: '#546e7a' },
            { t: 'Propias del nodo', n: num(cu.propio), sub: 'no se tocan', icono: 'candado', color: '#6a1b9a' }, { t: 'Con error', n: num(cu.error), sub: 'no entran', icono: 'alarmas', color: '#b71c1c' }, { t: 'Sin referencia', n: num(cu.sin_referencia), sub: 'servicio, empresa o equipo que no existe', icono: 'filtro', color: '#ef6c00' }])}
          ${Object.entries(r.previos || {}).map(([k, v]) => `<p class="mini">${h(S.destinos[k].nombre)} que nombran las órdenes: ${num(v.nuevo)} nuevos, ${num(v.igual)} ya estaban.</p>`).join('')}
          ${r.planes ? `<p>${num(r.planes.planes)} planes${r.planes.agrupar ? ' (por equipo o instalación y frecuencia)' : ' (uno por tarea)'}; ${num(r.planes.sin_frecuencia)} sin frecuencia quedan en pausa${r.planes.habilitar ? '' : '; el resto también, hasta que los habilites'}.${cu.retirado ? ` ${num(cu.retirado)} planes traídos antes ya no están en el origen (cambió la frecuencia o se quitó la tarea): quedan en pausa, no se borran.` : ''}</p>
            ${tabla([{ t: 'Plan', v: (x) => x.titulo }, { t: 'Tareas', v: (x) => x.tareas }, { t: 'Cada', v: (x) => (x.cada_dias ? x.cada_dias + ' días' : '—') }, { t: 'Próxima', v: (x) => x.proxima || '—' }], r.planes.muestra)}` : ''}
          ${r.pmp ? `<p>${num(r.pmp.tareas)} tareas en el plan por semanas; ${num(r.pmp.sin_frecuencia)} sin frecuencia (se marcan a mano en el cronograma)${r.simulada ? '' : `; ${num(r.pmp.cronogramas_marcados)} con el cronograma marcado desde su próxima ejecución`}.</p>` : ''}
          ${sinEq.length ? `<h3>Valores sin equivalencia</h3>${sinEq.map(([l, v]) => `<p><b>${h(S.listas[l] || (l === 'frecuencia' ? 'Frecuencia (no está en la lista «Frecuencias de mantenimiento» de Configuración)' : l))}</b>: ${Object.entries(v).map(([k, n]) => `${h(k)} (${num(n)})`).join(' · ')}</p>`).join('')}<p class="mini">${r.destino === 'ordenes' ? 'Una orden cuyo estado no tiene equivalencia no entra. Tipo, prioridad y categoría sin equivalencia entran vacíos.' : ''}</p>` : ''}
          ${r.errores.length ? `<h3>Primeros errores</h3>${tabla([{ t: 'Fila del origen', v: (e) => e.fila }, { t: 'Motivo', v: (e) => e.motivo }], r.errores.slice(0, 30))}` : ''}
          ${r.simulada ? `<div class="acciones"><button class="b" id="mgIr" ${cu.nuevo + cu.cambio ? '' : 'disabled'}>Cargar ${num(cu.nuevo + cu.cambio)} registros</button></div><p class="mini">La carga se puede repetir: lo que no cambió en el origen no se vuelve a escribir.</p>` : ''}</div>`;
        const b = $('#mgIr', z); if (b) b.onclick = () => correr(false); };
      $('#mgLeer', z).onclick = async () => { const m = $('#mgM1', z);
        try { aviso(m, 'info', 'Leyendo el origen…'); E.an = await api('POST', 'm/migracion/analizar', { origen: juntar(), destino: E.destino }); E.mapa = null; E.res = null; m.innerHTML = ''; paso3(); } catch (x) { aviso(m, 'mal', x.message); } };
      if (E.an) { paso3(); if (E.res) resultado(E.res); }
    }

    if (E.sub === 'equivalencias') {
      z.innerHTML = `<div class="tarjeta"><h2>Tablas de equivalencias</h2><p class="mini">Cómo se traduce cada valor de otro sistema a PIM. Vienen cargadas las más habituales; se pueden cambiar y agregar. Un valor seguido de «|con_fin» o «|con_inicio» se aplica solo a las órdenes que tienen esa fecha.</p></div>
        ${Object.entries(S.listas).map(([l, nom]) => `<div class="tarjeta"><h2>${h(nom)}</h2>${tabla([{ t: 'Valor de origen', v: (e) => e.etiqueta }, { t: 'Pasa a (PIM)', html: true, v: (e) => `<select class="eqS" data-l="${l}" data-o="${h(e.etiqueta)}">${opPim(l, e.destino)}</select>` },
            { t: 'Nota', html: true, v: (e) => `<input class="eqN" data-l="${l}" data-o="${h(e.etiqueta)}" value="${h(e.nota)}" maxlength="240">` }, { t: '', html: true, v: (e) => `<button class="b peligro eqB" data-l="${l}" data-o="${h(e.etiqueta)}" title="Quitar">✕</button>` }], S.equivalencias[l])}
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px"><input class="eqNv" data-l="${l}" placeholder="Valor nuevo del origen" style="flex:1 1 220px;width:auto"><button class="b sec eqAdd" data-l="${l}">Agregar</button><button class="b eqG" data-l="${l}">Guardar</button></div><div class="eqM" data-l="${l}"></div></div>`).join('')}`;
      const enviar = async (l, lista) => { const m = $(`.eqM[data-l="${l}"]`, z); try { await api('PUT', 'm/migracion/equivalencias/' + l, { equivalencias: lista }); Nucleo.ir('configuracion'); } catch (x) { aviso(m, 'mal', x.message); } };
      $$('.eqG', z).forEach((b) => b.onclick = () => enviar(b.dataset.l, $$(`.eqS[data-l="${b.dataset.l}"]`, z).map((s) => ({ etiqueta: s.dataset.o, destino: s.value, nota: $$(`.eqN[data-l="${b.dataset.l}"]`, z).find((n) => n.dataset.o === s.dataset.o)?.value || '' }))));
      $$('.eqAdd', z).forEach((b) => b.onclick = () => { const v = $(`.eqNv[data-l="${b.dataset.l}"]`, z).value.trim(); if (v) enviar(b.dataset.l, [{ etiqueta: v, destino: '' }]); });
      $$('.eqB', z).forEach((b) => b.onclick = () => enviar(b.dataset.l, [{ etiqueta: b.dataset.o, borrar: true }]));
    }

    if (E.sub === 'historial') {
      z.innerHTML = `<div class="tarjeta"><h2>Cargas y simulaciones</h2>${tabla([{ t: 'N.º', v: (r) => r.id }, { t: 'Fecha', v: (r) => fecha(r.creado) }, { t: 'Quién', v: (r) => r.creado_por }, { t: 'Origen', v: (r) => `${r.origen} · ${r.origen_detalle}` }, { t: 'Destino', v: (r) => S.destinos[r.destino]?.nombre || r.destino },
        { t: 'Tipo', html: true, v: (r) => r.simulada ? '<span class="et gris">Simulación</span>' : '<span class="et verde">Carga</span>' }, { t: 'Filas', v: (r) => num(r.filas) }, { t: 'Nuevas', v: (r) => num(r.cuenta.nuevo) }, { t: 'Cambian', v: (r) => num(r.cuenta.cambio) }, { t: 'Con error', v: (r) => num(r.cuenta.error) }], S.corridas)}
        <p class="mini">Se guardan las cuentas de cada corrida, no los datos ni las claves.</p></div>`;
    }

    if (E.sub === 'ajustes') {
      z.innerHTML = `<div class="tarjeta"><h2>Detalle escrito de los pedidos</h2><p class="mini">El texto que escribe quien pide un trabajo <b>siempre se guarda</b>. Este ajuste decide si el Servidor PIM lo muestra: en las sábanas de este nodo y a los sistemas conectados por la API.
        Conviene apagarlo si los pedidos pueden traer datos de pacientes y el nodo comparte órdenes con terceros.</p>
        <label class="fchk"><input type="checkbox" id="ajD" ${S.ajustes.exponer_detalle ? 'checked' : ''}> Mostrar el detalle escrito de los pedidos</label><div id="ajM"></div></div>
        <div class="tarjeta"><h2>Sigla del establecimiento</h2><p class="mini">Cada establecimiento codifica sus equipos e instalaciones a su modo. La sigla (4 caracteres) se antepone al código de cada equipo médico y de cada sistema de instalación, para saber de quién es el recurso cuando varios nodos comparten datos. Ejemplo: con la sigla <b>HEJa</b>, el código <code>GAS-001-TAN-1</code> queda <code>HEJa-GAS-001-TAN-1</code>. Se busca igual con o sin la sigla.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><input id="ajS" maxlength="4" style="width:8em" placeholder="HEJa" value="${h(S.ajustes.sigla || '')}"><button class="b" id="ajSG">Guardar sigla</button></div>
        <p class="mini" id="ajSN">${S.ajustes.sigla ? `${num(S.ajustes.recursos_con_sigla)} de ${num(S.ajustes.recursos)} equipos y sistemas llevan la sigla.` : `Sin sigla: los ${num(S.ajustes.recursos || 0)} equipos y sistemas llevan solo su código.`} Lo ya cargado toma la sigla al volver a traerlo desde «Traer datos» o al editarlo.</p><div id="ajSM"></div></div>`;
      $('#ajSG', z).onclick = async () => { try { const r = await api('PUT', 'm/migracion/ajustes', { sigla: $('#ajS', z).value.trim() }); S.ajustes = r.ajustes; aviso($('#ajSM', z), 'ok', r.ajustes.sigla ? `Sigla guardada: ${r.ajustes.sigla}. Los equipos y sistemas nuevos la llevan desde ahora.` : 'Sin sigla: los códigos nuevos se guardan tal como se escriben.'); } catch (x) { aviso($('#ajSM', z), 'mal', x.message); } };
      $('#ajD', z).onchange = async (e) => { try { const r = await api('PUT', 'm/migracion/ajustes', { exponer_detalle: e.target.checked }); aviso($('#ajM', z), 'ok', r.ajustes.exponer_detalle ? 'Ahora el detalle se muestra.' : 'El detalle queda guardado pero no se muestra.'); } catch (x) { aviso($('#ajM', z), 'mal', x.message); e.target.checked = !e.target.checked; } };
    }
  } });
})();
