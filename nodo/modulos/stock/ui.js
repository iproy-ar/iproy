// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo stock · pestaña «Stock» con subpestañas Insumos / Ingreso / Egreso / Movimientos / Proveedores y widget del inicio.
// Casi todo se elige de listas: categoría y subcategoría (dan el código {n}-CAT-SUB), marca, unidad, depósito, origen, motivo,
// proveedor, orden de trabajo y servicio de destino. Lo único que se escribe es la descripción del insumo y los números de comprobante.
(() => {
  const { h, aviso, tabla, panel, form, api, pim, puede, seccion, kpis, listas, textoLista, numero } = Nucleo;
  const LISTAS_STOCK = ['stock_categoria', 'stock_subcategoria', 'unidad', 'fabricante', 'stock_deposito', 'stock_origen', 'stock_motivo_egreso', 'especialidad'];
  const fmt = (n) => (n == null || n === '' ? '—' : Number(n).toLocaleString('es-AR', { maximumFractionDigits: 3 }));
  const plata = (n) => (n == null || n === '' ? '—' : '$ ' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
  const enCampo = (n) => (n == null || n === '' ? '' : String(+n).replace('.', ','));
  const hoy = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const existencia = (i) => `<span class="et ${+i.existencia <= 0 ? 'rojo' : +i.minimo > 0 && +i.existencia < +i.minimo ? 'nar' : 'verde'}">${fmt(i.existencia)} ${h(i.unidad)}</span>`;
  let sub = 'insumos'; let filtro = { cat: '', q: '', bajo: false, todos: false };

  // ---------------------------------------------------------------- opciones que vienen de otros lados
  const proveedores = async () => (await api('GET', 'm/stock/proveedores')).proveedores.map((p) => [p.id, p.nombre + (p.especialidad ? ' · ' + textoLista('especialidad', p.especialidad) : '')]);
  const ordenesAbiertas = async () => ((await pim('GET', 'Task?status=requested,received,accepted,ready,in-progress,on-hold&_count=200&_sort=-authored-on')).cuerpo.entry || [])
    .map((x) => x.resource).map((t) => [t.id, `${t.identifier?.[0]?.value || t.id} · ${t.focus?.display || t.description || ''}`.slice(0, 120)]);
  const servicios = async () => ((await pim('GET', 'Location?_count=500')).cuerpo.entry || []).map((x) => x.resource).filter((l) => l.status !== 'inactive')
    .map((l) => [l.id, l.name || l.id]).sort((a, b) => a[1].localeCompare(b[1]));

  Nucleo.tablero(async (el) => {
    const r = (await api('GET', 'm/stock/resumen')).resumen;
    el.innerHTML = kpis([{ t: 'Insumos', n: r.insumos, sub: 'activos en el pañol', icono: 'stock', color: '#1e88e5' },
      { t: 'Bajo mínimo', n: r.bajo_minimo, sub: 'conviene reponer', icono: 'alarmas', color: r.bajo_minimo ? '#fb8c00' : '#43a047' },
      { t: 'Sin existencia', n: r.sin_existencia, sub: 'en cero', icono: 'reloj', color: r.sin_existencia ? '#e53935' : '#43a047' },
      { t: 'Movimientos', n: r.mov_30, sub: 'comprobantes en 30 días', icono: 'hist', color: '#8e24aa' }]);
    el.querySelectorAll('.kpi').forEach((k, i) => { k.style.cursor = 'pointer'; k.onclick = () => { sub = 'insumos'; filtro.bajo = i === 1; Nucleo.ir('stock'); }; });
  }, { id: 'stock.resumen', titulo: 'Stock del pañol' });

  Nucleo.pestana({ id: 'stock', titulo: 'Stock', orden: 25, cap: 'stock.ver', render: async (el) => {
    const tabs = [['insumos', 'Insumos'], ...(puede('stock.mover') ? [['ingreso', 'Ingreso'], ['egreso', 'Egreso']] : []), ['movimientos', 'Movimientos'], ['proveedores', 'Proveedores']];
    if (!tabs.some((t) => t[0] === sub)) sub = 'insumos';
    el.innerHTML = `<div class="pest">${tabs.map(([k, t]) => `<button data-s="${k}" class="${k === sub ? 'activo' : ''}">${t}</button>`).join('')}</div><div id="stC"></div>`;
    el.querySelectorAll('.pest button').forEach((b) => b.onclick = () => { sub = b.dataset.s; Nucleo.ir('stock'); });
    await listas(LISTAS_STOCK);
    await VISTAS[sub](el.querySelector('#stC'));
  } });

  const VISTAS = {
    // ---------------------------------------------------------------- insumos
    async insumos(c) {
      const [r, l] = await Promise.all([api('GET', 'm/stock/resumen'), api('GET', 'm/stock/insumos' + (filtro.todos ? '?todos=1' : ''))]);
      const R = r.resumen; const todos = l.insumos;
      const cats = (await listas(['stock_categoria'])).stock_categoria.valores;
      c.innerHTML = `${kpis([{ t: 'Insumos', n: R.insumos, sub: 'activos', icono: 'stock', color: '#1e88e5' }, { t: 'Bajo mínimo', n: R.bajo_minimo, sub: 'conviene reponer', icono: 'alarmas', color: '#fb8c00' },
          { t: 'Sin existencia', n: R.sin_existencia, sub: 'en cero', icono: 'reloj', color: '#e53935' }, { t: 'Valor estimado', n: plata(R.valor), sub: 'existencia × último precio', icono: 'diario', color: '#43a047' }])}
        <div class="tarjeta"><h2>Insumos del pañol</h2>
          <div class="filtros"><div style="min-width:180px"><label>Categoría</label><select id="sCat"><option value="">Todas</option>${cats.map((v) => `<option value="${h(v.codigo)}" ${v.codigo === filtro.cat ? 'selected' : ''}>${h(v.texto)}</option>`).join('')}</select></div>
            <div style="flex:1;min-width:200px"><label>Buscar</label><input id="sQ" type="search" placeholder="Código, descripción o medida" value="${h(filtro.q)}"></div>
            <label class="fchk"><input type="checkbox" id="sBajo" ${filtro.bajo ? 'checked' : ''}> Solo bajo mínimo</label>
            ${puede('stock.editar') ? `<label class="fchk"><input type="checkbox" id="sTodos" ${filtro.todos ? 'checked' : ''}> Ver desactivados</label>` : ''}</div>
          <div id="sLista"></div>
          ${puede('stock.editar') ? seccion('Nuevo insumo', formInsumo()) : ''}</div>`;
      const pintar = () => {
        const t = filtro.q.trim().toLowerCase();
        const v = todos.filter((i) => (!filtro.cat || i.categoria === filtro.cat) && (!filtro.bajo || (+i.minimo > 0 && +i.existencia < +i.minimo))
          && (!t || `${i.codigo} ${i.descripcion} ${i.modelo || ''} ${textoLista('fabricante', i.marca)}`.toLowerCase().includes(t)));
        c.querySelector('#sLista').innerHTML = `<p class="mini">${v.length} de ${todos.length} insumos</p>` + tabla([{ t: 'Código', v: (i) => i.codigo },
          { t: 'Descripción', html: true, v: (i) => `${h(i.descripcion)}${i.modelo ? ` <small class="mini">${h(i.modelo)}</small>` : ''}${+i.activo ? '' : ' <span class="et gris">desactivado</span>'}` },
          { t: 'Rubro', v: (i) => textoLista('stock_subcategoria', i.subcategoria) }, { t: 'Marca', v: (i) => textoLista('fabricante', i.marca) },
          { t: 'Existencia', html: true, v: existencia }, { t: 'Mínimo', v: (i) => (+i.minimo ? fmt(i.minimo) : '') }, { t: 'Depósito', v: (i) => textoLista('stock_deposito', i.deposito) }], v.slice(0, 500), detalleInsumo);
      };
      pintar();
      c.querySelector('#sCat').onchange = (e) => { filtro.cat = e.target.value; pintar(); };
      c.querySelector('#sQ').oninput = (e) => { filtro.q = e.target.value; pintar(); };
      c.querySelector('#sBajo').onchange = (e) => { filtro.bajo = e.target.checked; pintar(); };
      const st = c.querySelector('#sTodos'); if (st) st.onchange = (e) => { filtro.todos = e.target.checked; Nucleo.ir('stock'); };
    },
    ingreso: (c) => movimiento(c, true),
    egreso: (c) => movimiento(c, false),
    // ---------------------------------------------------------------- movimientos (agrupados por comprobante)
    async movimientos(c) {
      c.innerHTML = `<div class="tarjeta"><h2>Movimientos</h2><div class="filtros"><div style="min-width:150px"><label>Tipo</label><select id="mT"><option value="">Todos</option><option value="ingreso">Ingresos</option><option value="egreso">Egresos</option></select></div>
        <div><label>Desde</label><input type="date" id="mD" value="${new Date(Date.now() - 90 * 864e5).toISOString().slice(0, 10)}"></div><div><label>Hasta</label><input type="date" id="mH" value="${hoy()}"></div></div><div id="mL"></div></div>`;
      const cargar = async () => {
        const q = new URLSearchParams({ tipo: c.querySelector('#mT').value, desde: c.querySelector('#mD').value, hasta: c.querySelector('#mH').value });
        const m = (await api('GET', 'm/stock/movimientos?' + q)).movimientos;
        const g = []; const idx = {};
        m.forEach((x) => { if (!idx[x.clave]) { idx[x.clave] = { ...x, lineas: [] }; g.push(idx[x.clave]); } idx[x.clave].lineas.push(x); });
        c.querySelector('#mL').innerHTML = tabla([{ t: 'Comprobante', v: (x) => x.clave }, { t: 'Fecha', v: (x) => x.fecha.split('-').reverse().join('/') },
          { t: 'Tipo', html: true, v: (x) => `<span class="et ${x.tipo === 'ingreso' ? 'verde' : 'nar'}">${x.tipo === 'ingreso' ? 'Ingreso' : 'Egreso'}</span>` },
          { t: 'Motivo', v: (x) => x.tipo === 'ingreso' ? textoLista('stock_origen', x.origen) : textoLista('stock_motivo_egreso', x.motivo) },
          { t: 'Detalle', v: (x) => [x.proveedor, x.orden_numero, x.destino_nombre, x.comprobante].filter(Boolean).join(' · ') },
          { t: 'Renglones', v: (x) => x.lineas.length === 1 ? `${x.codigo} × ${fmt(x.cantidad)}` : `${x.lineas.length} insumos` }, { t: 'Usuario', v: (x) => x.usuario || '' }], g, detalleMov);
      };
      c.querySelectorAll('#mT,#mD,#mH').forEach((x) => x.onchange = cargar); await cargar();
    },
    // ---------------------------------------------------------------- proveedores
    async proveedores(c) {
      const p = (await api('GET', 'm/stock/proveedores' + (puede('stock.editar') ? '?todos=1' : ''))).proveedores;
      c.innerHTML = `<div class="tarjeta"><h2>Proveedores</h2>${tabla([{ t: 'Nombre', html: true, v: (x) => h(x.nombre) + (+x.activo ? '' : ' <span class="et gris">desactivado</span>') },
        { t: 'Rubro', v: (x) => textoLista('especialidad', x.especialidad) }, { t: 'Contacto', v: (x) => x.contacto || '' }, { t: 'Teléfono', v: (x) => x.telefono || '' },
        { t: 'Correo', v: (x) => x.email || '' }, { t: 'Último ingreso', v: (x) => x.ultimo_ingreso ? x.ultimo_ingreso.split('-').reverse().join('/') : '' }], p, puede('stock.editar') ? editarProveedor : null)}
        ${puede('stock.editar') ? seccion('Nuevo proveedor', form(camposProveedor({}), 'Guardar proveedor', async (d, m) => {
          await api('POST', 'm/stock/proveedores', d); aviso(m, 'ok', 'Proveedor guardado.'); setTimeout(() => Nucleo.ir('stock'), 700); })) : ''}</div>`;
    },
  };

  // ---------------------------------------------------------------- formularios de insumo
  function formInsumo() {
    return form([{ n: 'categoria', t: 'Categoría', tipo: 'lista', lista: 'stock_categoria', sigla: true, req: true },
      { n: 'subcategoria', t: 'Subcategoría', tipo: 'lista', lista: 'stock_subcategoria', padre: 'categoria', sigla: true, req: true },
      { n: 'descripcion', t: 'Descripción', req: true, ph: 'Ej.: Lámpara LED' }, { n: 'modelo', t: 'Medida o modelo', ph: 'Ej.: 9 W E27 luz fría' },
      { n: 'marca', t: 'Marca', tipo: 'lista', lista: 'fabricante', vacio: '— sin marca —' }, { n: 'unidad', t: 'Unidad', tipo: 'lista', lista: 'unidad', v: 'u', otro: false },
      { n: 'minimo', t: 'Stock mínimo', tipo: 'decimal', ayuda: 'Avisa cuando hay menos' }, { n: 'precio', t: 'Último precio unitario', tipo: 'decimal', unidad: '$' },
      { n: 'deposito', t: 'Depósito', tipo: 'lista', lista: 'stock_deposito', v: 'PANOL' },
      { n: 'existencia_inicial', t: 'Existencia actual', tipo: 'decimal', ayuda: 'Si ya hay en el pañol: queda como ajuste de inventario' }], 'Crear insumo', async (d, m) => {
      Object.keys(d).forEach((k) => (d[k] === '' || d[k] === null) && delete d[k]);
      const r = await api('POST', 'm/stock/insumos', d);
      aviso(m, 'ok', `Insumo creado con el código ${r.insumo.codigo}.`); setTimeout(() => Nucleo.ir('stock'), 1100);
    }, { extra: '<span class="mini cod-prev"></span>', alCargar: (f) => {
      const prev = f.querySelector('.cod-prev');
      const ver = async () => { const ca = f.elements.categoria.value, su = f.elements.subcategoria.value;
        if (!ca || !su || ca === '__otro' || su === '__otro') { prev.textContent = 'El código se arma con la categoría y la subcategoría.'; return; }
        try { prev.innerHTML = `Código que se asignará: <b>${h((await api('GET', `m/stock/codigo?categoria=${encodeURIComponent(ca)}&subcategoria=${encodeURIComponent(su)}`)).codigo)}</b>`; } catch (x) { prev.textContent = ''; } };
      f.elements.categoria.addEventListener('change', ver); f.elements.subcategoria.addEventListener('change', ver); ver();
    } });
  }

  async function detalleInsumo(i) {
    const p = panel(`${i.codigo} · ${i.descripcion}`, '<p class="mini">Cargando…</p>');
    const j = await api('GET', 'm/stock/insumos/' + i.id); const x = j.insumo;
    p.innerHTML = `<dl><dt>Rubro</dt><dd>${h(textoLista('stock_categoria', x.categoria))} › ${h(textoLista('stock_subcategoria', x.subcategoria))}</dd>
        <dt>Medida o modelo</dt><dd>${h(x.modelo || '—')}</dd><dt>Marca</dt><dd>${h(textoLista('fabricante', x.marca) || '—')}</dd>
        <dt>Existencia</dt><dd>${existencia(x)}${+x.minimo ? ` <small class="mini">mínimo ${fmt(x.minimo)}</small>` : ''}</dd><dt>Último precio</dt><dd>${plata(x.precio)}</dd>
        <dt>Depósito</dt><dd>${h(textoLista('stock_deposito', x.deposito) || '—')}</dd></dl>
      <h3 class="sub-t">Movimientos (ficha)</h3>${tabla([{ t: 'Fecha', v: (m) => m.fecha.split('-').reverse().join('/') }, { t: 'Comprobante', v: (m) => m.clave },
        { t: 'Cantidad', html: true, v: (m) => `<b style="color:${m.tipo === 'ingreso' ? '#2e7d32' : '#e65100'}">${m.tipo === 'ingreso' ? '+' : '−'}${fmt(m.cantidad)}</b>` }, { t: 'Saldo', v: (m) => fmt(m.saldo) },
        { t: 'Detalle', v: (m) => [m.tipo === 'ingreso' ? textoLista('stock_origen', m.origen) : textoLista('stock_motivo_egreso', m.motivo), m.proveedor, m.orden_numero, m.destino_nombre].filter(Boolean).join(' · ') }], j.movimientos)}
      ${puede('stock.editar') ? seccion('Editar insumo', form([{ n: 'descripcion', t: 'Descripción', req: true, v: x.descripcion }, { n: 'modelo', t: 'Medida o modelo', v: x.modelo || '' },
        { n: 'marca', t: 'Marca', tipo: 'lista', lista: 'fabricante', v: x.marca || '', vacio: '— sin marca —' }, { n: 'unidad', t: 'Unidad', tipo: 'lista', lista: 'unidad', v: x.unidad, otro: false },
        { n: 'minimo', t: 'Stock mínimo', tipo: 'decimal', v: enCampo(x.minimo) }, { n: 'precio', t: 'Último precio unitario', tipo: 'decimal', unidad: '$', v: enCampo(x.precio) },
        { n: 'deposito', t: 'Depósito', tipo: 'lista', lista: 'stock_deposito', v: x.deposito || '' }, { n: 'activo', t: 'Estado', tipo: 'check', v: +x.activo, ayudaCheck: 'Activo (se puede mover)' }],
        'Guardar cambios', async (d, m) => { d.activo = d.activo ? 1 : 0; await api('PUT', 'm/stock/insumos/' + x.id, d); aviso(m, 'ok', 'Guardado.'); setTimeout(() => Nucleo.ir('stock'), 700); })) : ''}`;
  }

  // ---------------------------------------------------------------- ingreso / egreso con renglones
  async function movimiento(c, ing) {
    const ins = (await api('GET', 'm/stock/insumos')).insumos;
    if (!ins.length) { c.innerHTML = `<div class="tarjeta"><h2>${ing ? 'Ingreso' : 'Egreso'}</h2><p class="mini">Todavía no hay insumos. Crealos en «Insumos › ＋ Nuevo insumo».</p></div>`; return; }
    const cab = ing
      ? [{ n: 'fecha', t: 'Fecha', tipo: 'date', v: hoy(), req: true }, { n: 'origen', t: 'Origen', tipo: 'lista', lista: 'stock_origen', req: true, v: 'COMPRA' },
        { n: 'proveedor', t: 'Proveedor', tipo: 'opciones', cargar: proveedores, vacio: '— sin proveedor —', ayuda: 'Los proveedores se cargan en su subpestaña' },
        { n: 'comprobante', t: 'Remito o factura N.º', ph: 'Ej.: R 0001-00012345' }, { n: 'expediente', t: 'Expediente', ph: 'Opcional' }, { n: 'resolucion', t: 'Resolución o disposición', ph: 'Opcional' },
        { n: 'observacion', t: 'Observación', ph: 'Opcional', ancho: 'todo' }]
      : [{ n: 'fecha', t: 'Fecha', tipo: 'date', v: hoy(), req: true }, { n: 'motivo', t: 'Motivo', tipo: 'lista', lista: 'stock_motivo_egreso', req: true, v: 'OT' },
        { n: 'orden', t: 'Orden de trabajo', tipo: 'opciones', cargar: ordenesAbiertas, vacio: '— sin orden —', ayuda: 'Obligatoria si el motivo es una orden' },
        { n: 'destino', t: 'Servicio o lugar de destino', tipo: 'opciones', cargar: servicios, vacio: '— sin destino —' },
        { n: 'solicitante', t: 'Quién retira', ph: 'Nombre y apellido' }, { n: 'comprobante', t: 'Vale N.º', ph: 'Opcional' }, { n: 'observacion', t: 'Observación', ph: 'Opcional', ancho: 'todo' }];
    c.innerHTML = `<div class="tarjeta"><h2>${ing ? 'Ingreso al pañol' : 'Egreso del pañol'}</h2><p class="mini">${ing ? 'Un comprobante puede tener varios renglones. El precio actualiza el «último precio» del insumo.' : 'No se puede sacar más de lo que hay.'}</p>
      ${form(cab, ing ? 'Registrar ingreso' : 'Registrar egreso', async (d, m, f) => {
        const lineas = [...f.querySelectorAll('.lin')].map((l, i) => { const s = l.querySelector('select').value; if (!s) return null;
          const q = numero(l.querySelector('.lin-cant input').value); const p = ing ? numero(l.querySelector('.lin-pre input').value) : null;
          if (q === null || Number.isNaN(q) || q <= 0) throw new Error(`Renglón ${i + 1}: escribí la cantidad`); if (Number.isNaN(p)) throw new Error(`Renglón ${i + 1}: el precio no es un número`);
          return { insumo: +s, cantidad: q, precio: p }; }).filter(Boolean);
        if (!lineas.length) throw new Error('Elegí al menos un insumo');
        const opt = (n) => f.elements[n]?.selectedOptions?.[0]; d.tipo = ing ? 'ingreso' : 'egreso'; d.lineas = lineas;
        if (!ing) { if (d.orden) d.orden_numero = opt('orden').text.split(' · ')[0]; if (d.destino) d.destino_nombre = opt('destino').text; }
        Object.keys(d).forEach((k) => (d[k] === '' || d[k] === null) && delete d[k]);
        const r = await api('POST', 'm/stock/movimientos', d);
        const bajos = r.renglones.filter((x) => x.bajo_minimo).map((x) => x.codigo);
        aviso(m, 'ok', `Registrado: ${r.clave} (${r.renglones.length} renglón/es).${bajos.length ? ' Quedan bajo el mínimo: ' + bajos.join(', ') + '.' : ''}`);
        f.querySelector('.lin-l').innerHTML = ''; renglon(f, ins, ing); f.reset(); f.elements.fecha.value = hoy();
      }, { alCargar: (f) => {
        f.querySelector('.fgrid').insertAdjacentHTML('afterend', `<div class="lineas" data-ing="${ing ? 1 : ''}"><div class="lin-cab"><b>Renglones</b> <small class="mini">Elegí el insumo (podés buscarlo) y la cantidad.</small></div>
          <div class="lin-l"></div><div class="lin-pie"><button type="button" class="b sec lin-mas">＋ Agregar renglón</button><span class="mini lin-tot"></span></div></div>`);
        f.querySelector('.lin-mas').onclick = () => renglon(f, ins, ing); renglon(f, ins, ing);
      } })}</div>`;
  }
  function renglon(f, ins, ing) {
    const d = document.createElement('div'); d.className = 'lin';
    d.innerHTML = `<div class="lin-ins"><input type="search" placeholder="Buscar por código, descripción o medida…" aria-label="Buscar insumo"><select aria-label="Insumo"><option value="">— elegí el insumo —</option>
        ${ins.map((i) => `<option value="${i.id}" data-u="${h(i.unidad)}" data-e="${i.existencia}" data-p="${i.precio ?? ''}">${h(i.codigo)} · ${h(i.descripcion)}${i.modelo ? ' ' + h(i.modelo) : ''} (hay ${fmt(i.existencia)} ${h(i.unidad)})</option>`).join('')}</select><small class="mini lin-av"></small></div>
      <div class="fnum lin-cant"><input inputmode="decimal" placeholder="Cantidad" aria-label="Cantidad" autocomplete="off"><span>u</span></div>
      ${ing ? '<div class="fnum lin-pre"><input inputmode="decimal" placeholder="Precio unitario" aria-label="Precio unitario" autocomplete="off"><span>$</span></div>' : ''}
      <button type="button" class="b sec lin-x" title="Quitar renglón" aria-label="Quitar renglón">✕</button>`;
    f.querySelector('.lin-l').appendChild(d);
    const [q, s] = [d.querySelector('input[type=search]'), d.querySelector('select')]; const cant = d.querySelector('.lin-cant input'); const pre = d.querySelector('.lin-pre input');
    const total = () => { const ls = [...f.querySelectorAll('.lin')]; let t = 0; ls.forEach((l) => { const a = numero(l.querySelector('.lin-cant input').value), b = ing ? numero(l.querySelector('.lin-pre input').value) : null; if (a && b) t += a * b; });
      f.querySelector('.lin-tot').textContent = `${ls.filter((l) => l.querySelector('select').value).length} renglón/es${ing && t ? ' · total ' + plata(t) : ''}`; };
    const revisar = () => { const o = s.selectedOptions[0]; d.querySelector('.lin-cant span').textContent = o?.dataset.u || 'u';
      const av = d.querySelector('.lin-av'); const n = numero(cant.value);
      av.textContent = !ing && o?.value && n > +o.dataset.e ? `Solo hay ${fmt(o.dataset.e)} ${o.dataset.u}` : ''; av.style.color = '#c62828'; total(); };
    q.oninput = () => { const t = q.value.trim().toLowerCase(); let primero = null;
      [...s.options].forEach((op) => { const ok = !t || !op.value || op.text.toLowerCase().includes(t); op.hidden = !ok; if (ok && op.value && !primero) primero = op; });
      if (t && primero && (s.selectedOptions[0]?.hidden || !s.value)) { s.value = primero.value; s.dispatchEvent(new Event('change')); } };
    s.onchange = () => { const o = s.selectedOptions[0]; if (ing && pre && !pre.value && o?.dataset.p) pre.value = enCampo(o.dataset.p); revisar(); if (o?.value) cant.focus(); };
    cant.oninput = revisar; if (pre) pre.oninput = total;
    d.querySelector('.lin-x').onclick = () => { if (f.querySelectorAll('.lin').length > 1) d.remove(); else { s.value = ''; cant.value = ''; if (pre) pre.value = ''; q.value = ''; q.oninput(); } total(); };
    total();
  }

  function detalleMov(x) {
    panel(`${x.clave} · ${x.tipo === 'ingreso' ? 'Ingreso' : 'Egreso'}`, `<dl><dt>Fecha</dt><dd>${h(x.fecha.split('-').reverse().join('/'))}</dd>
      <dt>${x.tipo === 'ingreso' ? 'Origen' : 'Motivo'}</dt><dd>${h(x.tipo === 'ingreso' ? textoLista('stock_origen', x.origen) : textoLista('stock_motivo_egreso', x.motivo))}</dd>
      ${x.proveedor ? `<dt>Proveedor</dt><dd>${h(x.proveedor)}</dd>` : ''}${x.comprobante ? `<dt>Comprobante</dt><dd>${h(x.comprobante)}</dd>` : ''}
      ${x.expediente ? `<dt>Expediente</dt><dd>${h(x.expediente)}</dd>` : ''}${x.resolucion ? `<dt>Resolución</dt><dd>${h(x.resolucion)}</dd>` : ''}
      ${x.orden_numero ? `<dt>Orden de trabajo</dt><dd>${h(x.orden_numero)}</dd>` : ''}${x.destino_nombre ? `<dt>Destino</dt><dd>${h(x.destino_nombre)}</dd>` : ''}
      ${x.solicitante ? `<dt>Retiró</dt><dd>${h(x.solicitante)}</dd>` : ''}${x.observacion ? `<dt>Observación</dt><dd>${h(x.observacion)}</dd>` : ''}<dt>Registró</dt><dd>${h(x.usuario || '—')}</dd></dl>
      <h3 class="sub-t">Renglones</h3>${tabla([{ t: 'Insumo', v: (l) => `${l.codigo} · ${l.descripcion}` }, { t: 'Cantidad', v: (l) => `${fmt(l.cantidad)} ${l.unidad}` },
        { t: 'Precio', v: (l) => (l.precio != null ? plata(l.precio) : '') }, { t: 'Saldo', v: (l) => fmt(l.saldo) }], x.lineas)}`);
  }

  const camposProveedor = (p) => [{ n: 'nombre', t: 'Nombre o razón social', req: true, v: p.nombre || '' }, { n: 'especialidad', t: 'Rubro', tipo: 'lista', lista: 'especialidad', v: p.especialidad || '', vacio: '— sin rubro —' },
    { n: 'contacto', t: 'Contacto', v: p.contacto || '', ph: 'Persona o área' }, { n: 'telefono', t: 'Teléfono', tipo: 'tel', v: p.telefono || '' },
    { n: 'email', t: 'Correo', tipo: 'email', v: p.email || '' }, { n: 'direccion', t: 'Dirección', v: p.direccion || '' }];
  function editarProveedor(p) {
    const el = panel(p.nombre, '');
    el.innerHTML = form([...camposProveedor(p), { n: 'activo', t: 'Estado', tipo: 'check', v: +p.activo, ayudaCheck: 'Activo' }], 'Guardar cambios', async (d, m) => {
      d.activo = d.activo ? 1 : 0; await api('PUT', 'm/stock/proveedores/' + p.id, d); aviso(m, 'ok', 'Guardado.'); setTimeout(() => Nucleo.ir('stock'), 700); });
  }
})();
