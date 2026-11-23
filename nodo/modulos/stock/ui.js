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

  // 1.2 · accesos del grupo Pañol (mismos nombres que en el sistema anterior)
  Nucleo.pestana({ id: 'panol_stock', titulo: 'Ingreso de Stock', orden: 26, cap: 'stock.mover', grupo: 'panol', icono: 'stock', desc: 'Remito, orden de compra, expediente y renglones',
    render: async (el) => { await listas(LISTAS_STOCK); await movimiento(el, true); } });

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
  /** Alta de insumo. Con alCrear(insumo) no navega: devuelve el insumo creado (alta desde el ingreso, 1.3). */
  function formInsumo(alCrear) {
    return form([{ n: 'categoria', t: 'Categoría', tipo: 'lista', lista: 'stock_categoria', sigla: true, req: true },
      { n: 'subcategoria', t: 'Subcategoría', tipo: 'lista', lista: 'stock_subcategoria', padre: 'categoria', sigla: true, req: true },
      { n: 'descripcion', t: 'Descripción', req: true, ph: 'Ej.: Lámpara LED' }, { n: 'modelo', t: 'Medida o modelo', ph: 'Ej.: 9 W E27 luz fría' },
      { n: 'marca', t: 'Marca', tipo: 'lista', lista: 'fabricante', vacio: '— sin marca —' }, { n: 'unidad', t: 'Unidad', tipo: 'lista', lista: 'unidad', v: 'u', otro: false },
      { n: 'minimo', t: 'Stock mínimo', tipo: 'decimal', ayuda: 'Avisa cuando hay menos' }, { n: 'precio', t: 'Último precio unitario', tipo: 'decimal', unidad: '$' },
      { n: 'deposito', t: 'Depósito', tipo: 'lista', lista: 'stock_deposito', v: 'PANOL' },
      { n: 'existencia_inicial', t: 'Existencia actual', tipo: 'decimal', ayuda: 'Si ya hay en el pañol: queda como ajuste de inventario' }], 'Crear insumo', async (d, m) => {
      Object.keys(d).forEach((k) => (d[k] === '' || d[k] === null) && delete d[k]);
      const r = await api('POST', 'm/stock/insumos', d);
      aviso(m, 'ok', `Insumo creado con el código ${r.insumo.codigo}.`); if (alCrear) alCrear(r.insumo); else setTimeout(() => Nucleo.ir('stock'), 1100);
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
        { n: 'comprobante', t: 'Remito o factura N.º', ph: 'Ej.: R 0001-00012345' }, { n: 'orden_compra', t: 'Orden de compra N.º', ph: 'Opcional' },
        { n: 'expediente', t: 'Expediente', ph: 'Ej.: 1234-567-2026' }, { n: 'resolucion', t: 'Resolución o disposición', ph: 'Opcional' },
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
        if (ing) { filtroRubro(f); altasRapidas(f, ins); }
      } })}</div>`;
  }
  /** 1.3: «＋ Insumo nuevo» y «＋ Proveedor nuevo» sin salir del ingreso (como el sistema anterior): lo creado queda elegido y el formulario no se pierde. */
  function altasRapidas(f, ins) {
    const pie = f.querySelector('.lin-pie');
    if (puede('stock.editar')) {
      pie.insertAdjacentHTML('afterbegin', '<button type="button" class="b sec lin-nuevo">＋ Insumo nuevo</button>');
      pie.querySelector('.lin-nuevo').onclick = () => {
        const pnl = panel('Insumo nuevo', formInsumo((i) => {
          ins.push(i);
          const txt = `${i.codigo} · ${i.descripcion}${i.modelo ? ' ' + i.modelo : ''} (hay ${fmt(i.existencia)} ${i.unidad})`;
          f.querySelectorAll('.lin select').forEach((sel) => { const o = new Option(txt, i.id); o.dataset.u = i.unidad; o.dataset.e = i.existencia; o.dataset.p = i.precio ?? ''; sel.add(o); });
          let lin = [...f.querySelectorAll('.lin')].find((l) => !l.querySelector('select').value);
          if (!lin) { f.querySelector('.lin-mas').click(); lin = [...f.querySelectorAll('.lin')].pop(); }
          const sel = lin.querySelector('select'); sel.value = String(i.id); sel.dispatchEvent(new Event('change'));
          setTimeout(() => Nucleo.cerrar(), 900);
        }));
        listas(LISTAS_STOCK).then(() => null); pnl.querySelector('form')?.elements.descripcion?.focus();
      };
    }
    const sel = f.elements.proveedor;
    if (sel && puede('stock.editar')) {
      sel.closest('.fcampo').insertAdjacentHTML('beforeend', '<button type="button" class="b sec prov-nuevo" style="margin-top:6px">＋ Proveedor nuevo</button>');
      f.querySelector('.prov-nuevo').onclick = () => {
        const pnl = panel('Proveedor nuevo', form(camposProveedor({}), 'Guardar proveedor', async (d, m) => {
          const r = await api('POST', 'm/stock/proveedores', d); const pv = r.proveedor;
          sel.add(new Option(pv.nombre + (pv.especialidad ? ' · ' + textoLista('especialidad', pv.especialidad) : ''), pv.id)); sel.value = String(pv.id);
          const rb = f.querySelector('select.rubro'); if (rb) { rb.value = ''; [...sel.options].forEach((o) => { o.hidden = false; }); }
          aviso(m, 'ok', 'Proveedor guardado y elegido en el ingreso.'); setTimeout(() => Nucleo.cerrar(), 900);
        }));
        pnl.querySelector('form')?.elements.nombre?.focus();
      };
    }
  }
  /** 1.2: «Rubro del proveedor» arriba del proveedor (como «Especialidad» en el sistema anterior): achica la lista de proveedores. */
  async function filtroRubro(f) {
    const sel = f.elements.proveedor; if (!sel) return;
    const prov = (await api('GET', 'm/stock/proveedores')).proveedores; const rub = [...new Set(prov.map((p) => p.especialidad).filter(Boolean))];
    if (!rub.length) return;
    const caja = document.createElement('div'); caja.className = 'fcampo';
    caja.innerHTML = `<label>Rubro del proveedor</label><select class="rubro"><option value="">Todos los rubros</option>${rub.map((r) => `<option value="${h(r)}">${h(textoLista('especialidad', r))}</option>`).join('')}</select>`;
    sel.closest('.fcampo').before(caja);
    caja.querySelector('select').onchange = (e) => { const r = e.target.value; const ok = new Set(prov.filter((p) => !r || p.especialidad === r).map((p) => String(p.id)));
      [...sel.options].forEach((o) => { o.hidden = !!o.value && !ok.has(o.value); }); if (sel.selectedOptions[0]?.hidden) sel.value = ''; };
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
      ${x.orden_compra ? `<dt>Orden de compra</dt><dd>${h(x.orden_compra)}</dd>` : ''}${x.expediente ? `<dt>Expediente</dt><dd>${h(x.expediente)}</dd>` : ''}${x.resolucion ? `<dt>Resolución</dt><dd>${h(x.resolucion)}</dd>` : ''}
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

// ================================================================== 1.2 · Pañol: Pedir Insumos, Reportes de Stock e Imprimir Orden
// Equivalen a pedidos_panol.js, rpt_stock.js e impri_paniol.js del sistema anterior. Los cálculos (reposición, ABC, Holt) los hace el servidor (m/stock/analitica).
(() => {
  const { h, aviso, tabla, panel, api, pim, puede, kpis, alClicKpi, listas, textoLista, numero, csv, icono, grafico, fecha } = Nucleo;
  const fmt = (n, d = 1) => (n == null || n === '' ? '—' : Number(n).toLocaleString('es-AR', { maximumFractionDigits: d }));
  const plata = (n) => (n == null || n === '' ? '—' : '$ ' + Number(n).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
  const mesTxt = (m) => { const [a, b] = String(m).split('-'); return ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][+b - 1] + ' ' + a.slice(2); };
  const dmy = (f) => (f ? String(f).slice(0, 10).split('-').reverse().join('/') : '');
  const hoy = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const cat = (c) => textoLista('stock_categoria', c) || c;
  const abc = (c) => `<span class="et ${c === 'A' ? 'rojo' : c === 'B' ? 'nar' : c === 'C' ? 'verde' : 'gris'}" title="Clase ${c}">${c}</span>`;
  const imprimirDoc = (html) => { document.querySelectorAll('.soloimp').forEach((e) => e.remove()); const d = document.createElement('div'); d.className = 'imprimible soloimp'; d.innerHTML = html; document.body.appendChild(d); window.print(); };
  const LISTAS = ['stock_categoria', 'stock_subcategoria', 'especialidad', 'stock_origen', 'stock_motivo_egreso'];
  const S = { tab: 'faltantes', A: null, par: {}, borrador: [], f: { falt: 'criticos', q: '', cat: '', repSolo: true, repClase: '', segEst: '', segQ: '', td: {} }, ultimo: null };
  const PRIO = [['routine', 'Normal'], ['urgent', 'Urgente'], ['asap', 'Lo antes posible'], ['stat', 'Inmediata']];   // request-priority FHIR (PIM)
  const BANDA = { stat: 'A', asap: 'B', urgent: 'C', routine: 'D' };   // ConceptMap pim-banda-a-prioridad, al revés (la letra del número de pedido)
  const etBanda = (l) => `<span class="et ${l === 'A' ? 'rojo' : l === 'B' ? 'nar' : l === 'C' ? 'ama' : 'gris'}" title="Banda PIM ${h(l || '')}">${h(l || '—')}</span>`;
  const TABS = [['faltantes', 'Faltantes'], ['tendencias', 'Tendencias'], ['reposicion', 'Reposición'], ['pedido', 'Armar pedido', 1], ['seguimiento', 'Seguimiento'], ['conteo', 'Conteo cíclico', 1]];

  async function analitica(forzar) {
    if (S.A && !forzar) return S.A;
    S.A = await api('GET', 'm/stock/analitica?' + new URLSearchParams(S.par)); S.par = { ...S.A.parametros, ...S.par }; return S.A;
  }
  /** Órdenes abiertas para elegir en el pedido; primero las en espera (las «frenadas»). */
  const ordenesSelect = async () => { const l = ((await pim('GET', 'Task?status=requested,received,accepted,ready,in-progress,on-hold&_count=200&_sort=-_lastUpdated')).cuerpo.entry || []).map((x) => x.resource);
    l.sort((a, b) => (b.status === 'on-hold') - (a.status === 'on-hold'));
    return l.map((t) => [t.id, `${t.status === 'on-hold' ? '⏸ ' : ''}${t.identifier?.[0]?.value || t.id} · ${t.focus?.display || t.description || t.code?.text || ''}${t.priority ? ' · ' + (PRIO.find((p) => p[0] === t.priority)?.[1] || '') : ''}`.slice(0, 140)]); };
  async function frenadas() {   // órdenes en espera; «por insumos» cuando el motivo lo dice [supuesto: el sistema anterior lo marcaba con una casilla]
    try { const [l, n] = await Promise.all([pim('GET', 'Task?business-status=en-espera&_count=500&_sort=-_lastUpdated'), pim('GET', 'Task?business-status=en-espera&_summary=count')]);
      const t = (l.cuerpo.entry || []).map((x) => x.resource); const re = /insum|repuest|material|pañol|panol|compra|proveedor/i;
      return { total: n.cuerpo.total ?? t.length, revisadas: t.length, insumos: t.filter((x) => re.test(JSON.stringify([x.statusReason, x.businessStatus, x.note]))) };
    } catch (e) { return null; }
  }

  /** Enlace que abre el pedido (lo lleva el QR): la dirección pública del nodo si está configurada; si no, la de esta pantalla. */
  const enlace = (p) => p.enlace || `${location.origin}${location.pathname}#panol_insumos?pedido=${encodeURIComponent(p.numero)}`;
  function render(soloLectura) {
    return async (el) => {
      const pq = Nucleo.parametros.get('pedido');   // llegó por el QR o el enlace del correo
      if (pq) { S.tab = 'seguimiento'; S.abrir = pq; Nucleo.limpiarParametros(); }
      const opera = !soloLectura && puede('stock.mover');
      const tabs = TABS.filter((t) => !t[2] || opera); if (!tabs.some((t) => t[0] === S.tab)) S.tab = 'faltantes';
      await listas(LISTAS);
      el.innerHTML = `<div class="sab kfit"><div class="pest">${tabs.map(([k, t]) => `<button data-s="${k}" class="${k === S.tab ? 'activo' : ''}">${t}${k === 'pedido' && S.borrador.length ? ` <span class="et azul">${S.borrador.length}</span>` : ''}</button>`).join('')}
        <button class="b sec" id="ppAct" style="margin-left:auto" title="Volver a leer los datos">${icono('hist')} Actualizar</button></div><div id="ppC"><p class="mini">Calculando…</p></div></div>`;
      el.querySelectorAll('.pest button[data-s]').forEach((b) => b.onclick = () => { S.tab = b.dataset.s; render(soloLectura)(el); });
      el.querySelector('#ppAct').onclick = async () => { S.A = null; render(soloLectura)(el); };
      const c = el.querySelector('#ppC');
      try { await analitica(); await V[S.tab](c, opera, () => render(soloLectura)(el)); } catch (e) { c.innerHTML = `<div class="aviso error">${h(e.message)}</div>`; }
    };
  }
  const filtroCat = (id, v) => `<div style="min-width:170px"><label>Categoría</label><select id="${id}"><option value="">Todas</option>${S.A.categorias.map((k) => `<option value="${h(k)}" ${k === v ? 'selected' : ''}>${h(cat(k))}</option>`).join('')}</select></div>`;
  const alBorrador = (items) => { let n = 0; items.forEach((x) => { if (S.borrador.some((b) => b.id === x.id)) return; S.borrador.push({ id: x.id, codigo: x.codigo, descripcion: x.descripcion, unidad: x.unidad, cantidad: x.sugerido || 1, nota: '' }); n++; }); return n; };

  const V = {
    // ---------------------------------------------------------------- 1 · Faltantes
    async faltantes(c, opera, redibujar) {
      const A = S.A, K = A.kpis, f = S.f; const fr = await frenadas();
      const val = (k) => ({ criticos: (x) => x.critico, sinstock: (x) => x.sin_stock, minimo: (x) => x.bajo_minimo, negativos: (x) => x.negativo, todos: () => true })[k];
      c.innerHTML = kpis([{ t: 'Sin stock con consumo', n: K.sin_stock, sub: 'en cero y se usan', icono: 'alarmas', color: '#e53935', k: 'sinstock', activo: f.falt === 'sinstock' },
        { t: 'Críticos', n: K.criticos, sub: `se acaban antes de ${A.parametros.plazo} días`, icono: 'reloj', color: '#fb8c00', k: 'criticos', activo: f.falt === 'criticos' },
        { t: 'Órdenes frenadas', n: fr ? fr.insumos.length : '—', sub: fr ? `por insumos · de ${fr.total} en espera${fr.revisadas < fr.total ? ` (vistas las ${fr.revisadas} más recientes)` : ''}` : 'no se pudo leer', icono: 'ordenes', color: '#8e24aa', k: 'frenadas' },
        { t: 'Bajo mínimo', n: K.bajo_minimo, sub: 'existencia < mínimo', icono: 'stock', color: '#1e88e5', k: 'minimo', activo: f.falt === 'minimo' },
        { t: 'Valor a reponer', n: plata(K.valor_reponer), sub: `${K.sugeridos} insumos sugeridos${K.sin_precio ? ` · ${K.sin_precio} sin precio` : ''}`, icono: 'diario', color: '#43a047', k: 'reponer' }])
        + `<div class="tarjeta"><h2>Faltantes del pañol</h2><div class="filtros"><div class="chips">${[['criticos', 'Críticos'], ['sinstock', 'Sin stock'], ['minimo', 'Bajo mínimo'], ['negativos', 'Negativos'], ['todos', 'Todos']]
          .map(([k, t]) => `<button class="chip ${f.falt === k ? 'activo' : ''}" data-f="${k}">${t}</button>`).join('')}</div>${filtroCat('fCat', f.cat)}
          <div style="flex:1;min-width:180px"><label>Buscar</label><input id="fQ" type="search" value="${h(f.q)}" placeholder="Código o descripción"></div></div><div id="fL"></div>
          <div class="acciones"><button class="b sec" id="fCsv">${icono('bajar')} CSV</button>${opera ? '<button class="b" id="fPed">Pasar los sugeridos al pedido</button>' : ''}</div><div id="fM"></div></div>`;
      let vis = [];
      const pintar = () => { const t = f.q.trim().toLowerCase();
        vis = A.items.filter(val(f.falt)).filter((x) => (!f.cat || x.categoria === f.cat) && (!t || `${x.codigo} ${x.descripcion}`.toLowerCase().includes(t)))
          .sort((a, b) => (a.cobertura ?? 1e9) - (b.cobertura ?? 1e9));
        c.querySelector('#fL').innerHTML = `<p class="mini">${vis.length} insumos · consumo medido en ${A.ventana.map(mesTxt).join(', ')}</p>` + tabla([{ t: 'Código', v: (x) => x.codigo }, { t: 'Descripción', v: (x) => x.descripcion },
          { t: 'Categoría', v: (x) => cat(x.categoria) }, { t: 'Stock', html: true, v: (x) => `<b style="color:${x.existencia <= 0 ? '#c62828' : x.critico ? '#e65100' : 'inherit'}">${fmt(x.existencia, 3)}</b> ${h(x.unidad)}` },
          { t: 'Mínimo', v: (x) => (x.minimo ? fmt(x.minimo, 3) : '') }, { t: 'Consumo/mes', v: (x) => fmt(x.mensual) }, { t: 'Cobertura', v: (x) => (x.cobertura == null ? (x.existencia > 0 ? 'sin consumo' : '—') : fmt(x.cobertura, 0) + ' días') },
          { t: 'En pedido', v: (x) => (x.en_pedido ? fmt(x.en_pedido) : '') }, { t: 'Sugerido', html: true, v: (x) => (x.sugerido ? `<b>${fmt(x.sugerido)}</b>` : '') }, { t: 'ABC', html: true, v: (x) => abc(x.abc) }], vis.slice(0, 500)); };
      pintar();
      c.querySelectorAll('.chip').forEach((b) => b.onclick = () => { f.falt = b.dataset.f; c.querySelectorAll('.chip').forEach((x) => x.classList.toggle('activo', x === b)); pintar(); });
      alClicKpi(c, (k) => { if (k === 'frenadas') return verFrenadas(fr); if (k === 'reponer') { S.tab = 'reposicion'; return redibujar(); } f.falt = k; c.querySelectorAll('.chip').forEach((x) => x.classList.toggle('activo', x.dataset.f === k)); pintar(); });
      c.querySelector('#fCat').onchange = (e) => { f.cat = e.target.value; pintar(); }; c.querySelector('#fQ').oninput = (e) => { f.q = e.target.value; pintar(); };
      c.querySelector('#fCsv').onclick = () => csv('faltantes_panol', [{ t: 'Código', v: (x) => x.codigo }, { t: 'Descripción', v: (x) => x.descripcion }, { t: 'Categoría', v: (x) => cat(x.categoria) },
        { t: 'Stock', v: (x) => x.existencia }, { t: 'Unidad', v: (x) => x.unidad }, { t: 'Mínimo', v: (x) => x.minimo }, { t: 'Consumo/mes', v: (x) => x.mensual }, { t: 'Cobertura días', v: (x) => x.cobertura ?? '' },
        { t: 'En pedido', v: (x) => x.en_pedido }, { t: 'Sugerido', v: (x) => x.sugerido }, { t: 'Precio', v: (x) => x.precio ?? '' }, { t: 'ABC', v: (x) => x.abc }], vis);
      const bp = c.querySelector('#fPed'); if (bp) bp.onclick = () => { const n = alBorrador(vis.filter((x) => x.sugerido > 0)); aviso(c.querySelector('#fM'), n ? 'ok' : 'error', n ? `${n} insumos pasaron a «Armar pedido».` : 'No hay sugeridos nuevos en esta lista.'); };
    },
    // ---------------------------------------------------------------- 2 · Tendencias (Holt a = 0,5 · b = 0,3)
    async tendencias(c) {
      const f = S.f.td; const q = new URLSearchParams({ ...S.par, ...f });
      const T = await api('GET', 'm/stock/tendencias?' + q); const M = T.disponibles;
      const op = (sel) => M.map((m) => `<option value="${m}" ${m === sel ? 'selected' : ''}>${mesTxt(m)}</option>`).join('');
      const sel = (id, v, ops) => `<select id="${id}">${ops.map(([k, t]) => `<option value="${k}" ${k === v ? 'selected' : ''}>${t}</option>`).join('')}</select>`;
      const un = T.metrica === 'valor' ? plata : (v) => fmt(v);
      const opc = [...S.A.categorias.map((k) => [k, 'Categoría · ' + cat(k)]), ...S.A.items.filter((x) => x.consumo12 > 0 || Object.keys(x.ing).length).map((x) => [x.codigo, x.codigo + ' · ' + x.descripcion])];
      c.innerHTML = `<div class="tarjeta"><h2>Tendencias del pañol</h2><div class="filtros">
          <div><label>Desde</label><select id="tD">${op(T.meses[0])}</select></div><div><label>Hasta</label><select id="tH">${op(T.meses[T.meses.length - 1])}</select></div>
          <div><label>Movimiento</label>${sel('tS', T.serie, [['egr', 'Consumo (egresos)'], ['ing', 'Ingresos']])}</div><div><label>Medir en</label>${sel('tM', T.metrica, [['cant', 'Cantidad'], ['valor', 'Valor ($)']])}</div>
          <div><label>Agrupar por</label>${sel('tV', T.vista, [['categoria', 'Categoría'], ['insumo', 'Insumo']])}</div>
          <div style="flex:2;min-width:220px"><label>Insumo o categoría del gráfico</label><select id="tC"><option value="">Todo el pañol</option>${opc.map(([k, t]) => `<option value="${h(k)}" ${k === T.grafico.cual ? 'selected' : ''}>${h(t)}</option>`).join('')}</select></div></div>
        ${grafico({ tipo: 'lineas', titulo: 'Serie mensual y proyección', etiquetas: [...T.grafico.meses, ...T.grafico.proyeccion.meses].map(mesTxt), fmt: un, leyenda: true,
          series: [{ n: T.serie === 'ing' ? 'Ingresos' : 'Consumo', valores: [...T.grafico.valores, ...T.grafico.proyeccion.valores.map(() => 0)].slice(0, T.grafico.meses.length) },
            { n: 'Proyección (Holt)', punteada: true, color: '#fb8c00', desde: T.grafico.meses.length - 1, valores: [...T.grafico.valores, ...T.grafico.proyeccion.valores] }] })}
        <p class="mini">Tendencia: ${T.grafico.pendiente >= 0 ? 'sube' : 'baja'} ${un(Math.abs(T.grafico.pendiente))} por mes (recta de mínimos cuadrados). La proyección usa Holt con a = 0,5 y b = 0,3.</p></div>
        <div class="tarjeta"><h2>${T.vista === 'categoria' ? 'Por categoría' : 'Por insumo'} · ${mesTxt(T.meses[0])} – ${mesTxt(T.meses[T.meses.length - 1])}</h2>
        ${tabla([{ t: T.vista === 'categoria' ? 'Categoría' : 'Insumo', v: (r) => (T.vista === 'categoria' ? cat(r.nombre) : r.nombre) }, { t: 'Período', v: (r) => un(r.periodo) }, { t: 'Anterior', v: (r) => (r.anterior == null ? '—' : un(r.anterior)) },
          { t: 'Variación', html: true, v: (r) => (r.variacion == null ? '—' : `<b style="color:${r.variacion > 0 ? '#e65100' : '#2e7d32'}">${r.variacion > 0 ? '+' : ''}${fmt(r.variacion)} %</b>`) },
          { t: 'Prom./mes', v: (r) => un(r.promedio) }, { t: 'Tendencia/mes', v: (r) => (r.tendencia > 0 ? '+' : '') + un(r.tendencia) }, { t: 'Proy. 3 meses', v: (r) => un(r.proyeccion3) }], T.filas)}
        <div class="acciones"><button class="b sec" id="tCsv">${icono('bajar')} CSV</button></div></div>`;
      const re = () => { Object.assign(f, { desde: c.querySelector('#tD').value, hasta: c.querySelector('#tH').value, serie: c.querySelector('#tS').value, metrica: c.querySelector('#tM').value, vista: c.querySelector('#tV').value, cual: c.querySelector('#tC').value }); V.tendencias(c); };
      c.querySelectorAll('select').forEach((x) => x.onchange = re);
      c.querySelector('#tCsv').onclick = () => csv('tendencias_panol', [{ t: 'Grupo', v: (r) => r.nombre }, { t: 'Período', v: (r) => r.periodo }, { t: 'Anterior', v: (r) => r.anterior ?? '' }, { t: 'Variación %', v: (r) => r.variacion ?? '' },
        { t: 'Promedio mensual', v: (r) => r.promedio }, { t: 'Tendencia mensual', v: (r) => r.tendencia }, { t: 'Proyección 3 meses', v: (r) => r.proyeccion3 }], T.filas);
    },
    // ---------------------------------------------------------------- 3 · Reposición (punto de pedido, stock de seguridad, ABC)
    async reposicion(c, opera, redibujar) {
      const A = S.A, P = A.parametros, f = S.f;
      c.innerHTML = `<div class="tarjeta"><h2>Parámetros de reposición</h2><div class="filtros">
          <div><label>Plazo de entrega (días)</label><input id="rL" inputmode="numeric" value="${P.plazo}"></div><div><label>Nivel de servicio (%)</label><select id="rN">${[90, 95, 97.5, 99].map((n) => `<option ${+P.nivel === n ? 'selected' : ''}>${n}</option>`).join('')}</select></div>
          <div><label>Ciclo de pedido (días)</label><input id="rC" inputmode="numeric" value="${P.ciclo}"></div><div><label>Meses de consumo</label><select id="rV">${[1, 2, 3, 6, 12].map((n) => `<option ${+P.ventana === n ? 'selected' : ''}>${n}</option>`).join('')}</select></div>
          <div style="align-self:end"><button class="b sec" id="rRe">Recalcular</button></div></div>
          <p class="mini">z = ${A.z} · stock de seguridad = z × desvío diario × √plazo · punto de pedido = consumo diario × plazo + seguridad · se sugiere pedir cuando existencia + en pedido ≤ punto de pedido, hasta el punto + consumo de un ciclo. Consumo = egresos para órdenes, consumo de servicios y bajas (${A.ventana.map(mesTxt).join(', ')}).</p></div>
        <div class="tarjeta"><h2>Reposición sugerida</h2><div class="filtros"><label class="fchk"><input type="checkbox" id="rS" ${f.repSolo ? 'checked' : ''}> Solo con sugerido</label>
          <div><label>Clase ABC</label><select id="rA"><option value="">Todas</option>${['A', 'B', 'C', 'N'].map((k) => `<option ${f.repClase === k ? 'selected' : ''}>${k}</option>`).join('')}</select></div>${filtroCat('rCat', f.cat)}
          <div style="flex:1;min-width:160px"><label>Buscar</label><input id="rQ" type="search" value="${h(f.q)}"></div></div><div id="rLst"></div>
          <div class="acciones"><button class="b sec" id="rCsv">${icono('bajar')} CSV</button>${opera ? '<button class="b" id="rPed">Pasar al pedido</button>' : ''}</div><div id="rM"></div></div>`;
      let vis = [];
      const pintar = () => { const t = f.q.trim().toLowerCase();
        vis = A.items.filter((x) => (!f.repSolo || x.sugerido > 0) && (!f.repClase || x.abc === f.repClase) && (!f.cat || x.categoria === f.cat) && (!t || `${x.codigo} ${x.descripcion}`.toLowerCase().includes(t)))
          .sort((a, b) => 'ABCN'.indexOf(a.abc) - 'ABCN'.indexOf(b.abc) || b.valor12 - a.valor12);
        c.querySelector('#rLst').innerHTML = `<p class="mini">${vis.length} insumos · valor sugerido ${plata(vis.reduce((s, x) => s + x.sugerido * (x.precio || 0), 0))}</p>` + tabla([{ t: 'Código', v: (x) => x.codigo }, { t: 'Descripción', v: (x) => x.descripcion },
          { t: 'ABC', html: true, v: (x) => abc(x.abc) }, { t: 'Stock', v: (x) => `${fmt(x.existencia, 3)} ${x.unidad}` }, { t: 'En pedido', v: (x) => (x.en_pedido ? fmt(x.en_pedido) : '') }, { t: 'Consumo/mes', v: (x) => fmt(x.mensual) },
          { t: 'Seguridad', v: (x) => fmt(x.ss) }, { t: 'Punto de pedido', v: (x) => fmt(x.rop) }, { t: 'Objetivo', v: (x) => fmt(x.objetivo) }, { t: 'Sugerido', html: true, v: (x) => (x.sugerido ? `<b>${fmt(x.sugerido)}</b>` : '') },
          { t: 'Cobertura', v: (x) => (x.cobertura == null ? '—' : fmt(x.cobertura, 0) + ' d') }], vis.slice(0, 500)); };
      pintar();
      const nuevoPar = () => { S.par = { plazo: c.querySelector('#rL').value, nivel: c.querySelector('#rN').value, ciclo: c.querySelector('#rC').value, ventana: c.querySelector('#rV').value }; S.A = null; redibujar(); };
      c.querySelector('#rRe').onclick = nuevoPar; c.querySelectorAll('#rN,#rV').forEach((x) => x.onchange = nuevoPar);
      c.querySelector('#rS').onchange = (e) => { f.repSolo = e.target.checked; pintar(); }; c.querySelector('#rA').onchange = (e) => { f.repClase = e.target.value; pintar(); };
      c.querySelector('#rCat').onchange = (e) => { f.cat = e.target.value; pintar(); }; c.querySelector('#rQ').oninput = (e) => { f.q = e.target.value; pintar(); };
      c.querySelector('#rCsv').onclick = () => csv('reposicion_panol', [{ t: 'Código', v: (x) => x.codigo }, { t: 'Descripción', v: (x) => x.descripcion }, { t: 'ABC', v: (x) => x.abc }, { t: 'Stock', v: (x) => x.existencia },
        { t: 'En pedido', v: (x) => x.en_pedido }, { t: 'Consumo mensual', v: (x) => x.mensual }, { t: 'Stock de seguridad', v: (x) => x.ss }, { t: 'Punto de pedido', v: (x) => x.rop }, { t: 'Objetivo', v: (x) => x.objetivo },
        { t: 'Sugerido', v: (x) => x.sugerido }, { t: 'Precio', v: (x) => x.precio ?? '' }], vis);
      const bp = c.querySelector('#rPed'); if (bp) bp.onclick = () => { const n = alBorrador(vis.filter((x) => x.sugerido > 0)); aviso(c.querySelector('#rM'), n ? 'ok' : 'error', n ? `${n} insumos pasaron a «Armar pedido».` : 'No hay sugeridos nuevos.'); };
    },
    // ---------------------------------------------------------------- 4 · Armar pedido
    async pedido(c, opera, redibujar) {
      const prov = (await api('GET', 'm/stock/proveedores')).proveedores; const A = S.A;
      const it = S.borrador;
      c.innerHTML = `<div class="tarjeta"><h2>Armar pedido de insumos</h2>
        <div class="fgrid"><div class="fcampo" data-todo="1"><label>Para la orden de trabajo</label><select id="pOr"><option value="">— sin orden (prioridad a mano) —</option></select>
            <small class="fayuda">Con una orden, PIM sugiere la prioridad: la de la orden o, si no tiene, el IRU o la categoría NFPA 99 de su equipo o instalación.</small></div>
          <div class="fcampo"><label>Prioridad</label><select id="pPr">${PRIO.map(([k, t]) => `<option value="${k}">${t} (banda ${BANDA[k]})</option>`).join('')}</select></div>
          <div class="fcampo" id="pMpC" style="display:none"><label>Motivo de otra prioridad <b class="req">*</b></label><input id="pMp" maxlength="300" placeholder="Por qué no se sigue la sugerida"></div>
          <div class="fcampo" data-todo="1"><div id="pSug" class="mini"></div></div>
          <div class="fcampo"><label>Se necesita para</label><input type="date" id="pNe"></div><div class="fcampo"><label>Destino</label><input id="pDe" placeholder="Ej.: Pañol central"></div>
          <div class="fcampo"><label>Proveedor sugerido</label><select id="pPv"><option value="">— sin proveedor —</option>${prov.map((p) => `<option value="${p.id}">${h(p.nombre)}</option>`).join('')}</select></div>
          <div class="fcampo" data-todo="1"><label>Motivo <b class="req">*</b></label><input id="pMo" placeholder="Ej.: Reposición mensual · insumos críticos"></div>
          <div class="fcampo" data-todo="1"><label>Observaciones</label><textarea id="pOb" rows="2"></textarea></div></div>
        <h3 class="sub-t">Renglones (${it.length})</h3><div id="pIt"></div>
        <div class="filtros"><div style="flex:1;min-width:220px"><label>Agregar insumo</label><select id="pAd"><option value="">— elegí —</option>${A.items.map((x) => `<option value="${x.id}">${h(x.codigo)} · ${h(x.descripcion)} (hay ${fmt(x.existencia, 3)} ${h(x.unidad)})</option>`).join('')}</select></div></div>
        <div class="acciones"><button class="b" id="pOk">Registrar el pedido</button><button class="b sec" id="pVac">Vaciar</button></div><div id="pM"></div><div id="pRes"></div></div>`;
      const pintar = () => { c.querySelector('#pIt').innerHTML = it.length ? tabla([{ t: 'Código', v: (x) => x.codigo }, { t: 'Descripción', v: (x) => x.descripcion },
          { t: 'Cantidad', html: true, v: (x) => `<input class="pCa" data-id="${x.id}" inputmode="decimal" value="${String(x.cantidad).replace('.', ',')}" style="width:90px"> ${h(x.unidad)}` },
          { t: 'Nota', html: true, v: (x) => `<input class="pNo" data-id="${x.id}" value="${h(x.nota)}" style="min-width:140px">` }, { t: '', html: true, v: (x) => `<button class="b sec pX" data-id="${x.id}" title="Quitar">✕</button>` }], it)
          : '<p class="mini">Sin renglones. Pasalos desde «Faltantes» o «Reposición», o agregalos acá abajo.</p>';
        c.querySelectorAll('.pCa').forEach((e) => e.oninput = () => { it.find((x) => x.id === +e.dataset.id).cantidad = numero(e.value); });
        c.querySelectorAll('.pNo').forEach((e) => e.oninput = () => { it.find((x) => x.id === +e.dataset.id).nota = e.value; });
        c.querySelectorAll('.pX').forEach((b) => b.onclick = () => { it.splice(it.findIndex((x) => x.id === +b.dataset.id), 1); pintar(); }); };
      pintar();
      c.querySelector('#pAd').onchange = (e) => { const x = A.items.find((y) => y.id === +e.target.value); if (x) alBorrador([{ ...x, sugerido: x.sugerido || 1 }]); e.target.value = ''; pintar(); };
      c.querySelector('#pVac').onclick = () => { it.splice(0); pintar(); };
      let sug = null; const prS = c.querySelector('#pPr');
      const otra = () => { c.querySelector('#pMpC').style.display = sug?.prioridad && prS.value !== sug.prioridad ? '' : 'none'; };
      prS.onchange = otra;
      ordenesSelect().then((l) => { c.querySelector('#pOr').insertAdjacentHTML('beforeend', l.map(([id, t]) => `<option value="${h(id)}">${h(t)}</option>`).join('')); }).catch(() => null);
      c.querySelector('#pOr').onchange = async (e) => { const v = e.target.value; const box = c.querySelector('#pSug'); sug = null;
        if (v) { try { sug = (await api('GET', 'm/stock/pedidos/sugerencia?orden=' + encodeURIComponent(v))).sugerencia; } catch (x) { box.textContent = x.message; } }
        if (sug?.prioridad) { prS.value = sug.prioridad; box.innerHTML = `Sugerida por PIM: <b>${h(PRIO.find((p) => p[0] === sug.prioridad)[1])}</b> ${etBanda(sug.letra)} · ${h(sug.fundamento)}${sug.activo_nombre ? ' · ' + h(sug.activo_nombre) : ''}`; }
        else if (v) box.textContent = 'La orden no tiene prioridad ni su activo IRU o categoría NFPA 99: elegila a mano.'; else box.textContent = '';
        otra(); };
      c.querySelector('#pOk').onclick = async () => {
        const m = c.querySelector('#pM');
        try { const r = await api('POST', 'm/stock/pedidos', { prioridad: prS.value, orden: c.querySelector('#pOr').value, motivo_prioridad: c.querySelector('#pMp').value, necesario: c.querySelector('#pNe').value, destino: c.querySelector('#pDe').value, proveedor: c.querySelector('#pPv').value,
            motivo: c.querySelector('#pMo').value, observaciones: c.querySelector('#pOb').value, lineas: it.map((x) => ({ insumo: x.id, cantidad: x.cantidad, nota: x.nota })) });
          it.splice(0); S.A = null; S.ultimo = r.pedido; pintar(); aviso(m, 'ok', `Pedido ${r.pedido.numero} registrado.`);
          c.querySelector('#pRes').innerHTML = `<div class="acciones"><button class="b sec" id="pImp">${icono('imprimir')} Imprimir ${h(r.pedido.numero)}</button></div>`;
          c.querySelector('#pImp').onclick = () => imprimirPedido(r.pedido);
          Nucleo.qr(enlace(r.pedido)).then((svg) => { c.querySelector('#pRes').insertAdjacentHTML('beforeend', `<div style="width:120px">${svg}</div>`); }).catch(() => null);
        } catch (e) { aviso(m, 'error', e.message); }
      };
    },
    // ---------------------------------------------------------------- 5 · Seguimiento (Solicitado / Recibido parcial / Recibido / Anulado)
    async seguimiento(c, opera) {
      const f = S.f;
      const P = (await api('GET', 'm/stock/pedidos?' + new URLSearchParams({ estado: f.segEst, q: f.segQ }))).pedidos;
      const cls = { Solicitado: 'azul', 'Recibido parcial': 'nar', Recibido: 'verde', Anulado: 'gris' };
      c.innerHTML = `<div class="tarjeta"><h2>Seguimiento de pedidos</h2><div class="filtros"><div class="chips">${[['', 'Todos'], ['Solicitado', 'Solicitados'], ['Recibido parcial', 'Recibidos parcial'], ['Recibido', 'Recibidos'], ['Anulado', 'Anulados']]
          .map(([k, t]) => `<button class="chip ${f.segEst === k ? 'activo' : ''}" data-e="${k}">${t}</button>`).join('')}</div>
          <div style="flex:1;min-width:180px"><label>Buscar</label><input id="sQ" type="search" value="${h(f.segQ)}" placeholder="Número, motivo o insumo"></div></div>
        ${tabla([{ t: 'Pedido', v: (p) => p.numero }, { t: 'Fecha', v: (p) => dmy(p.creado) }, { t: 'Estado', html: true, v: (p) => `<span class="et ${cls[p.estado]}">${h(p.estado)}</span>` },
          { t: 'Prioridad', html: true, v: (p) => `${etBanda(p.letra)} ${h(p.prioridad_texto || p.prioridad)}` }, { t: 'Orden', v: (p) => p.orden_numero || '' }, { t: 'Motivo', v: (p) => p.motivo || '' }, { t: 'Renglones', v: (p) => p.lineas.length }, { t: 'Proveedor', v: (p) => p.proveedor || '' }], P, (p) => verPedido(p, opera, () => V.seguimiento(c, opera)))}</div>`;
      c.querySelectorAll('.chip').forEach((b) => b.onclick = () => { f.segEst = b.dataset.e; V.seguimiento(c, opera); });
      if (S.abrir) { const num = S.abrir; S.abrir = null;
        const x = P.find((y) => y.numero === num) || (await api('GET', 'm/stock/pedidos?q=' + encodeURIComponent(num))).pedidos.find((y) => y.numero === num);
        if (x) verPedido(x, opera, () => V.seguimiento(c, opera)); else aviso(c.querySelector('.tarjeta'), 'error', `No se encontró el pedido ${num}.`); }
      c.querySelector('#sQ').onchange = (e) => { f.segQ = e.target.value; V.seguimiento(c, opera); };
    },
    // ---------------------------------------------------------------- 6 · Conteo cíclico (A cada 30 días, B 90, C 180)
    async conteo(c, opera) {
      const lote = S.f.lote || 15; const C = await api('GET', 'm/stock/conteo?' + new URLSearchParams({ ...S.par, lote }));
      const ajusta = puede('stock.editar');
      c.innerHTML = kpis([{ t: 'Exactitud de inventario', n: C.exactitud == null ? '—' : fmt(C.exactitud, 0) + ' %', sub: `conteos sin diferencia · 90 días (${C.contados_90})`, icono: 'ok', color: C.exactitud != null && C.exactitud < 90 ? '#e53935' : '#43a047' },
        { t: 'Pendientes clase A', n: C.pendientes.A, sub: `cada ${C.frecuencia.A} días`, icono: 'reloj', color: '#e53935' }, { t: 'Pendientes clase B', n: C.pendientes.B, sub: `cada ${C.frecuencia.B} días`, icono: 'reloj', color: '#fb8c00' },
        { t: 'Pendientes clase C', n: C.pendientes.C, sub: `cada ${C.frecuencia.C} días`, icono: 'reloj', color: '#1e88e5' }])
        + `<div class="tarjeta"><h2>Lote a contar (${C.lote.length} de ${C.total})</h2><p class="mini">Se cuenta un grupo chico por vez, más seguido los de clase A. Contá físicamente y cargá la cantidad. ${ajusta ? 'Si marcás «Ajustar el stock», las diferencias se registran como ajuste de inventario.' : 'El conteo no cambia el stock: las diferencias quedan para que el administrador las ajuste.'}</p>
          <div class="filtros"><div><label>Insumos por lote</label><select id="cLo">${[10, 15, 25, 40].map((n) => `<option ${lote === n ? 'selected' : ''}>${n}</option>`).join('')}</select></div></div>
          ${tabla([{ t: 'Código', v: (x) => x.codigo }, { t: 'Descripción', v: (x) => x.descripcion }, { t: 'Clase', html: true, v: (x) => abc(x.abc) }, { t: 'Último conteo', v: (x) => (x.ultimo ? dmy(x.ultimo) : 'nunca') },
            { t: 'Stock sistema', v: (x) => `${fmt(x.existencia, 3)} ${x.unidad}` }, { t: 'Contado', html: true, v: (x) => `<input class="cCo" data-id="${x.id}" data-s="${x.existencia}" inputmode="decimal" style="width:90px">` },
            { t: 'Diferencia', html: true, v: (x) => `<b class="cDi" data-id="${x.id}"></b>` }, { t: 'Nota', html: true, v: (x) => `<input class="cNo" data-id="${x.id}" style="min-width:120px">` }], C.lote)}
          <div class="acciones">${ajusta ? '<label class="fchk"><input type="checkbox" id="cAj"> Ajustar el stock con las diferencias</label>' : ''}<button class="b" id="cOk">Guardar el conteo</button></div><div id="cM"></div></div>
        <div class="tarjeta"><h2>Conteos recientes</h2>${tabla([{ t: 'Fecha', v: (r) => dmy(r.fecha) }, { t: 'Insumo', v: (r) => `${r.codigo} · ${r.descripcion}` }, { t: 'Sistema', v: (r) => fmt(r.sistema, 3) }, { t: 'Contado', v: (r) => fmt(r.contado, 3) },
          { t: 'Diferencia', html: true, v: (r) => `<b style="color:${+r.diferencia ? '#c62828' : '#2e7d32'}">${+r.diferencia > 0 ? '+' : ''}${fmt(r.diferencia, 3)}</b>` }, { t: 'Ajuste', v: (r) => r.ajuste || '' }, { t: 'Usuario', v: (r) => r.usuario || '' }], C.recientes.slice(0, 40))}</div>`;
      c.querySelector('#cLo').onchange = (e) => { S.f.lote = +e.target.value; V.conteo(c, opera); };
      c.querySelectorAll('.cCo').forEach((e) => e.oninput = () => { const n = numero(e.value), d = c.querySelector(`.cDi[data-id="${e.dataset.id}"]`);
        d.textContent = n == null || Number.isNaN(n) ? '' : (n - +e.dataset.s > 0 ? '+' : '') + fmt(n - +e.dataset.s, 3); d.style.color = n - +e.dataset.s ? '#c62828' : '#2e7d32'; });
      c.querySelector('#cOk').onclick = async () => {
        const ren = [...c.querySelectorAll('.cCo')].filter((e) => e.value.trim() !== '').map((e) => ({ insumo: +e.dataset.id, contado: numero(e.value), nota: c.querySelector(`.cNo[data-id="${e.dataset.id}"]`).value }));
        try { const r = await api('POST', 'm/stock/conteo', { renglones: ren, ajustar: !!c.querySelector('#cAj')?.checked });
          aviso(c.querySelector('#cM'), 'ok', `Conteo guardado: ${r.renglones.length} insumos, ${r.renglones.filter((x) => x.diferencia).length} con diferencia${r.ajustes.length ? ' · ajustes ' + r.ajustes.join(', ') : ''}.`);
          S.A = null; setTimeout(() => V.conteo(c, opera), 900);
        } catch (e) { aviso(c.querySelector('#cM'), 'error', e.message); }
      };
    },
  };

  function verFrenadas(fr) {
    if (!fr) return;
    panel('Órdenes frenadas por insumos', fr.insumos.length ? tabla([{ t: 'Orden', v: (t) => t.identifier?.[0]?.value || t.id }, { t: 'Trabajo', v: (t) => t.description || t.focus?.display || '' },
      { t: 'Empresa', v: (t) => t.owner?.display || '' }, { t: 'Motivo', v: (t) => t.statusReason?.text || t.businessStatus?.text || '' }, { t: 'Desde', v: (t) => fecha(t.lastModified || t.authoredOn) }], fr.insumos)
      : `<p class="mini">Ninguna de las ${fr.total} órdenes en espera menciona insumos, repuestos o compras en el motivo.</p>`);
  }
  function verPedido(p, opera, volver) {
    const activo = p.estado === 'Solicitado' || p.estado === 'Recibido parcial';
    const el = panel(`${p.numero} · ${p.estado}`, `<dl><dt>Prioridad</dt><dd>${etBanda(p.letra)} ${h(p.prioridad_texto || p.prioridad)}${p.fundamento ? ` <small class="mini">sugerida por PIM: ${h(PRIO.find((x) => x[0] === p.prioridad_sugerida)?.[1] || '')} · ${h(p.fundamento)}</small>` : ''}${p.motivo_prioridad ? `<br><small>Otra prioridad porque: ${h(p.motivo_prioridad)}</small>` : ''}</dd>
        ${p.orden_numero ? `<dt>Orden de trabajo</dt><dd>${h(p.orden_numero)}${p.activo_nombre ? ' · ' + h(p.activo_nombre) : ''}</dd>` : ''}${p.enviado ? `<dt>Enviado</dt><dd>${dmy(p.enviado)} a ${h(p.enviado_a || '')}</dd>` : ''}<dt>Motivo</dt><dd>${h(p.motivo || '')}</dd>${p.destino ? `<dt>Destino</dt><dd>${h(p.destino)}</dd>` : ''}
        ${p.necesario ? `<dt>Se necesita para</dt><dd>${dmy(p.necesario)}</dd>` : ''}${p.proveedor ? `<dt>Proveedor</dt><dd>${h(p.proveedor)}</dd>` : ''}${p.observaciones ? `<dt>Observaciones</dt><dd>${h(p.observaciones)}</dd>` : ''}
        <dt>Pidió</dt><dd>${h(p.creado_por || '—')} · ${dmy(p.creado)}</dd></dl>
      ${tabla([{ t: 'Renglón', v: (l) => l.renglon }, { t: 'Código', v: (l) => l.codigo }, { t: 'Descripción', v: (l) => l.descripcion }, { t: 'Pedido', v: (l) => `${fmt(l.cantidad, 3)} ${l.unidad}` },
        { t: 'Recibido', html: true, v: (l) => (activo && opera ? `<input class="vRe" data-l="${l.id}" inputmode="decimal" value="${+l.recibido ? String(+l.recibido).replace('.', ',') : ''}" style="width:90px">` : fmt(l.recibido, 3)) }], p.lineas)}
      ${activo && opera ? `<div class="fgrid"><div class="fcampo"><label class="fchk"><input type="checkbox" id="vIng" checked> Ingresar al stock lo que llega</label></div>
          <div class="fcampo"><label>Remito o factura N.º</label><input id="vRem"></div><div class="fcampo"><label>Orden de compra N.º</label><input id="vOc"></div></div>
        <div class="acciones"><button class="b" id="vRec">Registrar lo recibido</button><button class="b sec" id="vComp">Recibido completo</button><button class="b sec" id="vAn">Anular</button></div>` : ''}
      <div class="qrp" style="display:flex;gap:12px;align-items:center;margin:10px 0"><div id="vQr" style="width:120px;height:120px;flex:0 0 120px"></div><small class="mini">Código QR del pedido: abre esta ficha desde el papel o el celular.<br><a href="${h(enlace(p))}">${h(enlace(p))}</a></small></div>
      <div class="acciones"><button class="b sec" id="vImp">${icono('imprimir')} Imprimir</button><button class="b sec" id="vXl">${icono('bajar')} Planilla (.xlsx)</button></div>
      ${opera ? `<h3 class="sub-t">Enviar por correo</h3><div class="filtros"><div style="flex:1;min-width:220px"><label>Para</label><input id="vPara" value="${h(p.proveedor_email || '')}" placeholder="correo del proveedor (separá varios con coma)"></div>
        <label class="fchk"><input type="checkbox" id="vPl" checked> Adjuntar la planilla .xlsx</label><div style="align-self:end"><button class="b sec" id="vEnv">${icono('avisos')} Enviar</button></div></div>` : ''}<div id="vM"></div>`);
    el.querySelector('#vImp').onclick = () => imprimirPedido(p);
    Nucleo.qr(enlace(p)).then((svg) => { const q = el.querySelector('#vQr'); if (q) q.innerHTML = svg; }).catch(() => null);
    el.querySelector('#vXl').onclick = async () => { try { const r = await api('GET', `m/stock/pedidos/${p.id}/planilla`); const a = document.createElement('a');
      a.href = 'data:' + r.tipo + ';base64,' + r.base64; a.download = r.nombre; document.body.appendChild(a); a.click(); a.remove(); } catch (e) { aviso(el.querySelector('#vM'), 'error', e.message); } };
    if (el.querySelector('#vEnv')) el.querySelector('#vEnv').onclick = async () => { const b = el.querySelector('#vEnv'); b.disabled = true;
      try { const r = await api('POST', `m/stock/pedidos/${p.id}/enviar`, { para: el.querySelector('#vPara').value, planilla: el.querySelector('#vPl').checked });
        aviso(el.querySelector('#vM'), r.sin_qr ? 'info' : 'ok', `Enviado a ${r.enviados} destinatario/s.${r.sin_qr ? ' Salió sin el código QR ni el enlace: cargá la «Dirección de la app» en Configuración › Correo y recuperación.' : ''}`); } catch (e) { aviso(el.querySelector('#vM'), 'error', e.message); } b.disabled = false; };
    const enviar = async (accion, extra = {}) => {
      try { const r = await api('POST', `m/stock/pedidos/${p.id}/estado`, { accion, ingresar: el.querySelector('#vIng')?.checked, remito: el.querySelector('#vRem')?.value, orden_compra: el.querySelector('#vOc')?.value,
          recibidos: [...el.querySelectorAll('.vRe')].filter((e) => e.value !== '').map((e) => ({ linea: +e.dataset.l, recibido: numero(e.value) })), ...extra });
        S.A = null; aviso(el.querySelector('#vM'), 'ok', `${p.numero}: ${r.pedido.estado}${r.ingreso ? ' · ingreso ' + r.ingreso.clave : ''}.`); setTimeout(() => { Nucleo.cerrar(); volver(); }, 1100);
      } catch (e) { aviso(el.querySelector('#vM'), 'error', e.message); }
    };
    if (el.querySelector('#vRec')) {
      el.querySelector('#vRec').onclick = () => enviar('recibir'); el.querySelector('#vComp').onclick = () => enviar('completo');
      el.querySelector('#vAn').onclick = () => { const m = window.prompt('Motivo de la anulación'); if (m) enviar('anular', { motivo: m }); };
    }
  }
  async function imprimirPedido(p) {
    const s = Nucleo.sesion; let qr = ''; try { qr = await Nucleo.qr(enlace(p)); } catch (x) { /* sin QR se imprime igual */ }
    imprimirDoc(`<div style="display:flex;gap:16px;align-items:flex-start"><div style="flex:1"><h1>Pedido de insumos ${h(p.numero)}</h1><div>${h(s?.nodo?.nombre || '')} · impreso el ${fecha(new Date().toISOString())}</div></div>
        ${qr ? `<div style="width:110px;text-align:center;font-size:10px">${qr}<div>Escaneá para abrir el pedido</div></div>` : ''}</div>
      <table><tr><th>Estado</th><td>${h(p.estado)}</td></tr><tr><th>Prioridad</th><td>${h(p.prioridad_texto || p.prioridad)} (banda ${h(p.letra || '')})${p.fundamento ? ' · sugerida por PIM: ' + h(p.fundamento) : ''}${p.motivo_prioridad ? ' · otra prioridad porque: ' + h(p.motivo_prioridad) : ''}</td></tr>
        ${p.orden_numero ? `<tr><th>Orden de trabajo</th><td>${h(p.orden_numero)}${p.activo_nombre ? ' · ' + h(p.activo_nombre) : ''}</td></tr>` : ''}<tr><th>Motivo</th><td>${h(p.motivo || '')}</td></tr><tr><th>Destino</th><td>${h(p.destino || '')}</td></tr>
        <tr><th>Se necesita para</th><td>${dmy(p.necesario)}</td></tr><tr><th>Proveedor sugerido</th><td>${h(p.proveedor || '')}</td></tr><tr><th>Observaciones</th><td>${h(p.observaciones || '')}</td></tr></table>
      <table><tr><th style="width:auto">#</th><th style="width:auto">Código</th><th style="width:auto">Descripción</th><th style="width:auto">Cantidad</th><th style="width:auto">Precio ref.</th><th style="width:auto">Recibido</th></tr>
        ${p.lineas.map((l) => `<tr><td>${l.renglon}</td><td>${h(l.codigo)}</td><td>${h(l.descripcion)}${l.nota ? ' · ' + h(l.nota) : ''}</td><td>${fmt(l.cantidad, 3)} ${h(l.unidad)}</td><td>${l.precio != null ? plata(l.precio) : ''}</td><td>${+l.recibido ? fmt(l.recibido, 3) : ''}</td></tr>`).join('')}</table>
      <div class="firmas"><div>Pidió</div><div>Autorizó</div><div>Recibió</div></div>`);
  }

  Nucleo.pestana({ id: 'panol_insumos', titulo: 'Pedir Insumos', orden: 27, cap: 'stock.ver', grupo: 'panol', icono: 'stock', desc: 'Faltantes, tendencias, reposición, pedidos y conteo', render: render(false) });
  Nucleo.pestana({ id: 'empr_insumos', titulo: 'Pedir Insumos', orden: 28, cap: 'stock.ver', grupo: 'mant', icono: 'stock', desc: 'Faltantes, tendencias y reposición (solo lectura)', render: render(true), sinCap: 'stock.mover' });

  // ================================================================== Reportes de Stock
  const RS = { tipo: 'graficos', f: { desde: hoy().slice(0, 8) + '01', hasta: hoy(), categoria: '', subcategoria: '', proveedor: '', q: '', orden: '' }, cot: '' };
  const TIPOS_RS = [['graficos', 'Gráficos'], ['bajo_minimo', 'Stock bajo mínimo'], ['ingresos', 'Ingresos del período'], ['egresos', 'Egresos del período'], ['categoria', 'Por categoría'], ['por_ot', 'Egresos por OT'], ['inventario', 'Inventario total']];
  Nucleo.pestana({ id: 'rpt_stock', titulo: 'Reportes de Stock', orden: 29, cap: 'stock.ver', grupo: 'panol', icono: 'reportes', desc: 'Seis reportes, gráficos, impresión y planilla', render: async function pintar(el) {
    await listas(LISTAS); const prov = (await api('GET', 'm/stock/proveedores')).proveedores; const f = RS.f;
    const cats = Nucleo.listas ? (await listas(['stock_categoria'])).stock_categoria.valores : [];
    const subs = (await listas(['stock_subcategoria'])).stock_subcategoria.valores.filter((v) => !f.categoria || v.padre_codigo === f.categoria || String(v.codigo).startsWith(f.categoria + '-'));
    el.innerHTML = `<div class="sab kfit"><div class="pest">${TIPOS_RS.map(([k, t]) => `<button data-t="${k}" class="${k === RS.tipo ? 'activo' : ''}">${t}</button>`).join('')}</div>
      <div class="tarjeta"><div class="filtros"><div><label>Desde</label><input type="date" id="rsD" value="${f.desde}"></div><div><label>Hasta</label><input type="date" id="rsH" value="${f.hasta}"></div>
        <div style="min-width:160px"><label>Categoría</label><select id="rsC"><option value="">Todas</option>${cats.map((v) => `<option value="${h(v.codigo)}" ${v.codigo === f.categoria ? 'selected' : ''}>${h(v.texto)}</option>`).join('')}</select></div>
        <div style="min-width:160px"><label>Subcategoría</label><select id="rsS"><option value="">Todas</option>${subs.map((v) => `<option value="${h(v.codigo)}" ${v.codigo === f.subcategoria ? 'selected' : ''}>${h(v.texto)}</option>`).join('')}</select></div>
        <div style="min-width:160px"><label>Proveedor</label><select id="rsP"><option value="">Todos</option>${prov.map((p) => `<option value="${p.id}" ${String(p.id) === String(f.proveedor) ? 'selected' : ''}>${h(p.nombre)}</option>`).join('')}</select></div>
        <div style="flex:1;min-width:150px"><label>Buscar</label><input id="rsQ" type="search" value="${h(f.q)}" placeholder="Código o descripción"></div>
        <div style="width:120px"><label>Cotización US$</label><input id="rsU" inputmode="decimal" value="${h(RS.cot)}" placeholder="Opcional"></div></div></div><div id="rsR"><p class="mini">Cargando…</p></div></div>`;
    el.querySelectorAll('.pest button').forEach((b) => b.onclick = () => { RS.tipo = b.dataset.t; pintar(el); });
    const leer = () => { Object.assign(f, { desde: el.querySelector('#rsD').value, hasta: el.querySelector('#rsH').value, categoria: el.querySelector('#rsC').value, subcategoria: el.querySelector('#rsS').value, proveedor: el.querySelector('#rsP').value, q: el.querySelector('#rsQ').value }); RS.cot = el.querySelector('#rsU').value; };
    el.querySelectorAll('#rsD,#rsH,#rsP,#rsQ,#rsU').forEach((x) => x.onchange = () => { leer(); cuerpo(); });
    el.querySelector('#rsC').onchange = () => { leer(); f.subcategoria = ''; pintar(el); }; el.querySelector('#rsS').onchange = () => { leer(); cuerpo(); };
    const usd = () => { const c = numero(RS.cot); return c > 0 ? c : null; };
    const $ = (v) => plata(v) + (usd() ? ` <small class="mini">US$ ${fmt(v / usd(), 2)}</small>` : '');
    const r = el.querySelector('#rsR');
    async function cuerpo() {
      const q = new URLSearchParams(Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '')));
      if (RS.tipo === 'graficos') {
        const G = await api('GET', 'm/stock/reportes/graficos?' + q); const K = G.kpis; const pl = (v) => plata(v).replace(',00', '');
        r.innerHTML = kpis([{ t: 'Total ingresos', n: plata(K.ingresos), sub: usd() ? `US$ ${fmt(K.ingresos / usd(), 0)}` : `${dmy(G.desde)} – ${dmy(G.hasta)}`, icono: 'stock', color: '#43a047' },
            { t: 'Total egresos', n: plata(K.egresos), sub: usd() ? `US$ ${fmt(K.egresos / usd(), 0)}` : 'a precio del egreso', icono: 'stock', color: '#fb8c00' },
            { t: 'OTs con movimiento', n: K.ots, sub: 'órdenes con egresos', icono: 'ordenes', color: '#1e88e5' },
            { t: 'Insumo más usado', n: K.insumo_top ? K.insumo_top.codigo : '—', sub: K.insumo_top ? `${K.insumo_top.descripcion} · ${fmt(K.insumo_top.cantidad)} ${K.insumo_top.unidad}` : '', icono: 'estrella', color: '#8e24aa' },
            { t: 'Proveedor top', n: K.proveedor_top ? K.proveedor_top.nombre : '—', sub: K.proveedor_top ? plata(K.proveedor_top.importe) : '', icono: 'usuarios', color: '#00897b' }])
          + `<div class="grid2 ggraf"><div class="tarjeta"><h2>Egresos por categoría</h2><div class="filtros"><div style="flex:1"><label>Solo la orden</label><input id="rsO" value="${h(f.orden)}" placeholder="Número de orden (opcional)"></div></div>
              ${grafico({ tipo: 'torta', etiquetas: G.egresos_categoria.map((x) => cat(x.categoria)), series: [{ n: 'Egresos', valores: G.egresos_categoria.map((x) => x.importe) }], fmt: pl })}</div>
            <div class="tarjeta"><h2>Top 10 órdenes por costo</h2>${grafico({ tipo: 'barras', etiquetas: G.top_ot.map((x) => (x.orden_numero || x.orden) + (x.destino ? ' · ' + x.destino : '')), series: [{ n: 'Costo', valores: G.top_ot.map((x) => x.importe), color: '#fb8c00' }], fmt: pl })}</div>
            <div class="tarjeta"><h2>Ingresos y egresos por mes</h2>${grafico({ tipo: 'columnas', etiquetas: G.por_mes.map((x) => mesTxt(x.mes)), series: [{ n: 'Ingresos', valores: G.por_mes.map((x) => x.ingresos), color: '#43a047' }, { n: 'Egresos', valores: G.por_mes.map((x) => x.egresos), color: '#fb8c00' }], fmt: pl })}</div>
            <div class="tarjeta"><h2>Top proveedores</h2>${grafico({ tipo: 'barras', etiquetas: G.top_proveedores.map((x) => x.nombre), series: [{ n: 'Comprado', valores: G.top_proveedores.map((x) => x.importe), color: '#00897b' }], fmt: pl })}</div>
            <div class="tarjeta"><h2>Top insumos (cantidad egresada)</h2>${grafico({ tipo: 'barras', etiquetas: G.top_insumos.map((x) => `${x.codigo} · ${x.descripcion}`), series: [{ n: 'Cantidad', valores: G.top_insumos.map((x) => x.cantidad), color: '#8e24aa' }] })}</div>
            <div class="tarjeta"><h2>Stock por categoría (valor)</h2>${grafico({ tipo: 'torta', etiquetas: G.stock_categoria.map((x) => cat(x.categoria)), series: [{ n: 'Valor', valores: G.stock_categoria.map((x) => x.valor) }], fmt: pl })}</div></div>`;
        r.querySelector('#rsO').onchange = (e) => { f.orden = e.target.value.trim(); cuerpo(); };
        return;
      }
      const R = await api('GET', `m/stock/reportes/${RS.tipo}?` + q);
      const num = ['existencia', 'minimo', 'cantidad', 'falta', 'renglones', 'insumos', 'bajo_minimo'], din = ['precio', 'valor', 'importe', 'costo'];
      const cols = Object.entries(R.columnas).map(([k, t]) => ({ t, k, html: din.includes(k), v: (x) => (din.includes(k) ? (x[k] == null ? '—' : $(x[k])) : num.includes(k) ? fmt(x[k], 3)
        : ['fecha', 'primera', 'ultima'].includes(k) ? dmy(x[k]) : k === 'categoria' ? cat(x[k]) : k === 'subcategoria' ? textoLista('stock_subcategoria', x[k]) : k === 'origen' ? textoLista('stock_origen', x[k]) : k === 'motivo' ? textoLista('stock_motivo_egreso', x[k]) : (x[k] ?? '')) }));
      r.innerHTML = `<div class="tarjeta"><h2>${h(R.titulo)}${R.desde ? ` · ${dmy(R.desde)} – ${dmy(R.hasta)}` : ''}</h2><p class="mini">${R.totales.renglones} renglones · total ${$(R.totales.valor)}</p>${tabla(cols, R.filas)}
        <div class="acciones"><button class="b sec" id="rsI">${icono('imprimir')} Imprimir</button><button class="b sec" id="rsX">${icono('bajar')} Planilla (CSV)</button></div></div>`;
      r.querySelector('#rsX').onclick = () => csv('stock_' + RS.tipo, Object.entries(R.columnas).map(([k, t]) => ({ t, v: (x) => x[k] ?? '' })), R.filas);
      r.querySelector('#rsI').onclick = () => imprimirDoc(`<h1>${h(R.titulo)}</h1><div>${h(Nucleo.sesion?.nodo?.nombre || '')}${R.desde ? ` · ${dmy(R.desde)} – ${dmy(R.hasta)}` : ''} · impreso el ${fecha(new Date().toISOString())}</div>
        <table><tr>${cols.map((c) => `<th style="width:auto">${h(c.t)}</th>`).join('')}</tr>${R.filas.map((x) => `<tr>${cols.map((c) => `<td>${c.html ? c.v(x) : h(c.v(x))}</td>`).join('')}</tr>`).join('')}</table>
        <p><b>Total:</b> ${R.totales.renglones} renglones · ${plata(R.totales.valor)}${usd() ? ` (US$ ${fmt(R.totales.valor / usd(), 2)} a ${RS.cot})` : ''}</p>`);
    }
    await cuerpo();
  } });

  // ================================================================== Imprimir Orden de pañol (vale de egreso por orden, con firmas)
  Nucleo.pestana({ id: 'panol_imprimir', titulo: 'Imprimir Orden', orden: 30, cap: 'stock.ver', grupo: 'panol', icono: 'imprimir', desc: 'Vale del pañol por orden de trabajo, con firmas', render: async (el) => {
    const desde = new Date(Date.now() - 180 * 864e5).toISOString().slice(0, 10);
    const m = (await api('GET', `m/stock/movimientos?tipo=egreso&desde=${desde}`)).movimientos.filter((x) => x.orden);
    const g = {}; m.forEach((x) => { (g[x.orden] = g[x.orden] || { orden: x.orden, numero: x.orden_numero, destino: x.destino_nombre, fecha: x.fecha, solicitante: x.solicitante, lineas: [] }).lineas.push(x); });
    const L = Object.values(g).sort((a, b) => (a.fecha < b.fecha ? 1 : -1));
    el.innerHTML = `<div class="sab kfit"><div class="tarjeta"><h2>Imprimir orden de pañol</h2><p class="mini">Órdenes con egresos del pañol en los últimos 180 días. Tocá una para ver e imprimir el vale (código, descripción, cantidad, stock disponible y firmas).</p>
      <div class="filtros"><div style="flex:1;min-width:200px"><label>Buscar</label><input id="ioQ" type="search" placeholder="Número de orden, servicio o insumo"></div></div><div id="ioL"></div></div></div>`;
    const pintar = () => { const t = el.querySelector('#ioQ').value.trim().toLowerCase();
      el.querySelector('#ioL').innerHTML = tabla([{ t: 'Orden', v: (o) => o.numero || o.orden }, { t: 'Último egreso', v: (o) => dmy(o.fecha) }, { t: 'Servicio', v: (o) => o.destino || '' }, { t: 'Retiró', v: (o) => o.solicitante || '' },
        { t: 'Renglones', v: (o) => o.lineas.length }], L.filter((o) => !t || `${o.numero} ${o.destino} ${o.lineas.map((l) => l.codigo + ' ' + l.descripcion).join(' ')}`.toLowerCase().includes(t)), (o) => vale(o)); };
    el.querySelector('#ioQ').oninput = pintar; pintar();
    async function vale(o) {
      let T = null; try { T = (await pim('GET', 'Task/' + o.orden)).cuerpo; } catch (e) { /* la orden puede no estar en este nodo */ }
      const ins = (await api('GET', 'm/stock/insumos')).insumos; const ex = Object.fromEntries(ins.map((i) => [i.codigo, i.existencia]));
      const f = (t, v) => `<tr><th>${t}</th><td>${v || '&nbsp;'}</td></tr>`;
      const doc = `<h1>Orden de pañol · ${h(o.numero || o.orden)}</h1><div>${h(Nucleo.sesion?.nodo?.nombre || '')} · impresa el ${fecha(new Date().toISOString())}</div>
        <table>${f('N.º de orden', h(o.numero || ''))}${f('Servicio', h(o.destino || T?.location?.display || ''))}${f('Solicitante', h(T?.requester?.display || o.solicitante || ''))}${f('Empresa asignada', h(T?.owner?.display || ''))}
          ${f('Fecha de inicio', T?.executionPeriod?.start ? fecha(T.executionPeriod.start) : '')}${f('Tarea', h(T?.description || T?.focus?.display || ''))}</table>
        <table><tr><th style="width:auto">Comprobante</th><th style="width:auto">Fecha</th><th style="width:auto">Código</th><th style="width:auto">Descripción</th><th style="width:auto">Cantidad</th><th style="width:auto">Stock disp.</th></tr>
          ${o.lineas.map((l) => `<tr><td>${h(l.clave)}</td><td>${dmy(l.fecha)}</td><td>${h(l.codigo)}</td><td>${h(l.descripcion)}</td><td>${fmt(l.cantidad, 3)} ${h(l.unidad)}</td><td>${fmt(ex[l.codigo], 3)}</td></tr>`).join('')}</table>
        <div class="firmas"><div>Entregado</div><div>Recibido</div><div>Conformidad del Servicio</div><div>Auditoría de Mantenimiento</div></div>`;
      const p = panel('Orden de pañol ' + (o.numero || ''), `<div style="font-size:13px">${doc}</div><div class="acciones"><button class="b" id="ioI">${icono('imprimir')} Imprimir</button></div>`);
      p.querySelector('#ioI').onclick = () => imprimirDoc(doc);
    }
  } });
})();
