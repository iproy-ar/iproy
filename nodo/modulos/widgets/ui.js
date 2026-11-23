// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 Proyecto iProy y colaboradores
// Módulo widgets · catálogo del nodo (grupos, nombres, orden, perfiles) y widgets creados desde la pantalla:
// formulario, sábana, indicadores y página. Agrega la solapa «Widgets» a Configuración.
(() => {
  const { h, fecha, aviso, tabla, panel, cerrar, api, puede, kpis, alClicKpi, csv, icono, form, seccion } = Nucleo;
  const $ = (s, r = document) => r.querySelector(s); const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const TIPO_F = { texto: 'text', area: 'textarea', numero: 'number', fecha: 'date', hora: 'time', casilla: 'check' };
  let creados = []; let C = null;

  // ---------------------------------------------------------------- en cada ingreso: leer el catálogo y registrar los widgets creados
  async function cargar(redibujar) {
    creados.forEach((id) => Nucleo.quitarPestana(id)); creados = [];
    try { C = await api('GET', 'm/widgets/catalogo'); } catch (x) { C = null; Nucleo.catalogo(null, redibujar); return; }
    C.widgets.filter((w) => w.origen === 'definido' && w.activo).forEach((w) => {
      creados.push(w.id);
      Nucleo.pestana({ id: w.id, titulo: w.nombre, orden: w.orden, cap: 'widgets.usar', grupo: w.grupo || 'otros', icono: w.icono || 'modulo', desc: w.descripcion, definido: true, render: (el) => RENDER[w.tipo](el, w) });
    });
    Nucleo.catalogo(C, redibujar);
  }
  Nucleo.alIniciar(() => cargar(false));

  // ---------------------------------------------------------------- uso de un widget creado
  const campoForm = (c, v) => c.tipo === 'lista' ? { n: c.id, t: c.etiqueta, tipo: 'select', op: [['', '— Elegí —'], ...c.opciones.map((o) => [o, o])], v: v ?? '', req: c.requerido, ayuda: c.ayuda }
    : { n: c.id, t: c.etiqueta, tipo: TIPO_F[c.tipo] || 'text', v: v ?? '', req: c.requerido && c.tipo !== 'casilla', ayuda: c.ayuda, unidad: c.unidad };
  const mostrar = (c, v) => v == null || v === '' ? '' : c?.tipo === 'casilla' ? (v ? 'Sí' : 'No') : c?.tipo === 'fecha' ? String(v).split('-').reverse().join('/') : c?.tipo === 'numero' ? String(v).replace('.', ',') + (c.unidad ? ' ' + c.unidad : '') : String(v);
  const cabecera = (w) => `<div class="tarjeta"><h2>${h(w.nombre)}</h2>${w.definicion.ayuda ? `<p class="mini">${h(w.definicion.ayuda)}</p>` : ''}`;

  const RENDER = {
    async formulario(el, w) {
      const d = w.definicion;
      el.innerHTML = `${cabecera(w)}${form(d.campos.map((c) => campoForm(c)), d.boton || 'Guardar', async (v, m, f) => {
        const r = (await api('POST', 'm/widgets/datos/' + w.id, { datos: v })).registro; f.reset(); aviso(m, 'ok', `Guardado con el número ${r.numero}.`); mios();
      })}</div><div id="wMios"></div>`;
      const mios = async () => { const j = await api('GET', 'm/widgets/datos/' + w.id); if (!j.total) { $('#wMios', el).innerHTML = ''; return; }
        $('#wMios', el).innerHTML = `<div class="tarjeta"><h2>${puede('widgets.configurar') ? 'Registros' : 'Mis registros'} (${j.total})</h2>${tabla([{ t: 'N.º', v: (r) => r.numero }, { t: 'Fecha', v: (r) => fecha(r.creado) },
          ...d.campos.slice(0, 4).map((c) => ({ t: c.etiqueta, v: (r) => mostrar(c, r.datos[c.id]) })), ...(d.estados.length ? [{ t: 'Estado', v: (r) => r.estado }] : [])], j.registros.slice(0, 50))}</div>`; };
      await mios();
    },

    async sabana(el, w) {
      const d = w.definicion; const porId = Object.fromEntries(d.campos.map((c) => [c.id, c]));
      const F = { q: '', estado: '', desde: '', hasta: '', f: {} }; let pag = 0; let J = null; const POR = 50;
      const tit = (k) => k === 'numero' ? 'N.º' : k === 'estado' ? 'Estado' : k === 'creado' ? 'Fecha' : k === 'creado_por' ? 'Cargó' : porId[k].etiqueta;
      const val = (r, k) => k === 'numero' ? r.numero : k === 'estado' ? r.estado : k === 'creado' ? fecha(r.creado) : k === 'creado_por' ? r.creado_por : mostrar(porId[k], r.datos[k]);
      const edita = (k) => d.editables.includes(k) || (puede('widgets.configurar') && (porId[k] || k === 'estado'));
      const celda = (r, k) => {
        if (!edita(k)) return h(val(r, k));
        const c = k === 'estado' ? { tipo: 'lista', opciones: d.estados } : porId[k]; const v = k === 'estado' ? r.estado : r.datos[k];
        if (c.tipo === 'lista') return `<select class="wed" data-id="${r.id}" data-k="${k}">${(c.opciones.includes(v) || v == null || v === '' ? [''] : ['', v]).concat(c.opciones).filter((o, i, a) => a.indexOf(o) === i).map((o) => `<option ${o === (v ?? '') ? 'selected' : ''}>${h(o)}</option>`).join('')}</select>`;
        if (c.tipo === 'casilla') return `<input type="checkbox" class="wed" data-id="${r.id}" data-k="${k}" ${v ? 'checked' : ''}>`;
        return `<input class="wed" data-id="${r.id}" data-k="${k}" type="${c.tipo === 'fecha' ? 'date' : c.tipo === 'hora' ? 'time' : 'text'}" value="${h(v ?? '')}" ${c.tipo === 'numero' ? 'inputmode="decimal" style="max-width:110px"' : ''}>`;
      };
      el.innerHTML = `${cabecera(w)}<div id="sK"></div>
        <div class="filtros" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;margin:10px 0">
          <input id="sQ" type="search" placeholder="Buscar…" style="flex:1 1 200px;width:auto;min-width:160px">
          ${d.estados.length ? `<select id="sE" style="flex:0 1 190px;width:auto"><option value="">Todos los estados</option>${d.estados.map((e) => `<option>${h(e)}</option>`).join('')}</select>` : ''}
          ${d.filtros.filter((k) => porId[k]?.tipo === 'lista').map((k) => `<select data-f="${k}" style="flex:0 1 190px;width:auto"><option value="">${h(porId[k].etiqueta)}: todos</option>${porId[k].opciones.map((o) => `<option>${h(o)}</option>`).join('')}</select>`).join('')}
          <label class="mini" style="flex:0 1 150px">Desde <input id="sD" type="date"></label><label class="mini" style="flex:0 1 150px">Hasta <input id="sH" type="date"></label>
          <button class="b sec" id="sL">Limpiar</button>${d.descarga ? `<button class="b sec" id="sC">${icono('bajar')} CSV</button>` : ''}${d.imprimir ? `<button class="b sec" id="sP">${icono('imprimir')} Imprimir</button>` : ''}
        </div><div id="sT"></div><div id="sM"></div></div>
        ${d.alta || puede('widgets.configurar') ? seccion('Nuevo registro', form(d.campos.map((c) => campoForm(c)), d.boton || 'Guardar', async (v, m, f) => { const r = (await api('POST', 'm/widgets/datos/' + w.id, { datos: v })).registro; f.reset(); aviso(m, 'ok', `Guardado con el número ${r.numero}.`); leer(); })) : ''}`;
      const pintar = () => {
        const rs = J.registros; const pags = Math.max(1, Math.ceil(rs.length / POR)); pag = Math.min(pag, pags - 1); const ver = rs.slice(pag * POR, pag * POR + POR);
        if (d.estados.length) { $('#sK', el).innerHTML = kpis([{ t: 'Total', n: rs.length, k: '__todos', icono: 'ordenes', color: '#546e7a', activo: !F.estado }, ...d.estados.map((e) => ({ t: e, n: J.por_estado[e] || 0, k: e, icono: 'modulo', color: '#1e88e5', activo: F.estado === e }))]);
          alClicKpi($('#sK', el), (k) => { F.estado = k === '__todos' ? '' : k; const s = $('#sE', el); if (s) s.value = F.estado; pag = 0; leer(); }); }
        $('#sT', el).innerHTML = `${tabla(d.columnas.map((k) => ({ t: tit(k), html: true, v: (r) => celda(r, k) })), ver)}
          <p class="mini">${rs.length ? `${pag * POR + 1}–${pag * POR + ver.length} de ${rs.length}` : 'Sin registros con esos filtros'}${J.recortado ? ' · hay más: acotá el período' : ''}
          ${pags > 1 ? ` · <button class="b sec" id="sAnt" ${pag ? '' : 'disabled'}>Anterior</button> <button class="b sec" id="sSig" ${pag < pags - 1 ? '' : 'disabled'}>Siguiente</button>` : ''}</p>`;
        const a = $('#sAnt', el); if (a) a.onclick = () => { pag--; pintar(); }; const s = $('#sSig', el); if (s) s.onclick = () => { pag++; pintar(); };
        $$('.wed', el).forEach((x) => x.onchange = async () => {
          const r = J.registros.find((y) => y.id === +x.dataset.id); const k = x.dataset.k; const v = x.type === 'checkbox' ? x.checked : x.value;
          try { const n = (await api('PUT', `m/widgets/datos/${w.id}/${r.id}`, k === 'estado' ? { estado: v, revision: r.revision } : { datos: { [k]: v }, revision: r.revision })).registro;
            Object.assign(r, n); aviso($('#sM', el), 'ok', `Registro ${r.numero} guardado.`); if (k === 'estado') leer(); }
          catch (e) { aviso($('#sM', el), 'mal', e.message); leer(); }
        });
      };
      const leer = async () => { const p = new URLSearchParams(); ['q', 'estado', 'desde', 'hasta'].forEach((k) => F[k] && p.set(k, F[k])); Object.entries(F.f).forEach(([k, v]) => v && p.set(`f[${k}]`, v));
        J = await api('GET', `m/widgets/datos/${w.id}?${p}`); pintar(); };
      let t; $('#sQ', el).oninput = (e) => { clearTimeout(t); t = setTimeout(() => { F.q = e.target.value; pag = 0; leer(); }, 300); };
      const se = $('#sE', el); if (se) se.onchange = () => { F.estado = se.value; pag = 0; leer(); };
      $$('[data-f]', el).forEach((s) => s.onchange = () => { F.f[s.dataset.f] = s.value; pag = 0; leer(); });
      $('#sD', el).onchange = (e) => { F.desde = e.target.value; pag = 0; leer(); }; $('#sH', el).onchange = (e) => { F.hasta = e.target.value; pag = 0; leer(); };
      $('#sL', el).onclick = () => { Object.assign(F, { q: '', estado: '', desde: '', hasta: '', f: {} }); $$('.filtros input, .filtros select', el).forEach((x) => { x.value = ''; }); pag = 0; leer(); };
      const sc = $('#sC', el); if (sc) sc.onclick = () => csv(w.nombre, d.columnas.map((k) => ({ t: tit(k), v: (r) => val(r, k) })), J.registros);
      // Imprimir: la planilla de un mes (o de los filtros puestos), con todos sus registros, en limpio y con lugar para firmar
      const sp = $('#sP', el); if (sp) sp.onclick = () => { const m0 = new Date().toISOString().slice(0, 7);
        const p = Nucleo.panel('Imprimir planilla · ' + w.nombre, `<p class="mini">Se imprimen todos los registros que cumplen los filtros puestos en la pantalla${Object.values(F.f).some(Boolean) ? ' (' + Object.entries(F.f).filter(([, v]) => v).map(([k, v]) => `${h(tit(k))}: ${h(v)}`).join(' · ') + ')' : ''}.</p>
          <label>Mes</label><input type="month" id="pM" value="${F.desde ? '' : m0}"><small class="fayuda">Vacío: el período de los filtros de la pantalla.</small><div class="acciones"><button class="b" id="pI">${icono('imprimir')} Imprimir</button></div><div id="pMsg"></div>`);
        p.querySelector('#pI').onclick = async () => { const m = p.querySelector('#pM').value; const q = new URLSearchParams(); ['q', 'estado'].forEach((k) => F[k] && q.set(k, F[k])); Object.entries(F.f).forEach(([k, v]) => v && q.set(`f[${k}]`, v));
          let desde = F.desde, hasta = F.hasta; if (m) { const [y, mm] = m.split('-').map(Number); desde = `${m}-01`; hasta = `${m}-${String(new Date(y, mm, 0).getDate()).padStart(2, '0')}`; } if (desde) q.set('desde', desde); if (hasta) q.set('hasta', hasta);
          try { const R = await api('GET', `m/widgets/datos/${w.id}?${q}`); const rs = [...R.registros].reverse();
            document.querySelectorAll('.soloimp').forEach((e) => e.remove()); const dv = document.createElement('div'); dv.className = 'imprimible soloimp';
            const filtros = [m ? new Date(m + '-15').toLocaleDateString('es-AR', { month: 'long', year: 'numeric' }) : [desde, hasta].filter(Boolean).join(' a '), ...Object.entries(F.f).filter(([, v]) => v).map(([k, v]) => `${tit(k)}: ${v}`), F.estado ? 'Estado: ' + F.estado : ''].filter(Boolean).join(' · ');
            dv.innerHTML = `<h1>${h(w.nombre)}</h1><div>${h(Nucleo.sesion?.nodo?.nombre || '')}${filtros ? ' · ' + h(filtros) : ''} · ${rs.length} registro${rs.length === 1 ? '' : 's'} · impresa el ${Nucleo.fecha(new Date().toISOString())}</div>
              <table><tr><th style="width:auto">N.º</th>${d.columnas.map((k) => `<th style="width:auto">${h(tit(k))}</th>`).join('')}</tr>${rs.map((r) => `<tr><td>${h(r.numero)}</td>${d.columnas.map((k) => `<td>${h(String(val(r, k) ?? ''))}</td>`).join('')}</tr>`).join('') || `<tr><td colspan="${d.columnas.length + 1}">Sin registros.</td></tr>`}</table>
              ${R.recortado ? '<p>Hay más registros que los impresos: acotá el período.</p>' : ''}<div class="firmas"><div>Registró</div><div>Revisó</div><div>Responsable</div></div>`;
            document.body.appendChild(dv); Nucleo.cerrar(); window.print(); }
          catch (e) { aviso(p.querySelector('#pMsg'), 'mal', e.message); } }; };
      await leer();
    },

    async indicadores(el, w) {
      const j = await api('GET', 'm/widgets/datos/' + w.id);
      el.innerHTML = `${cabecera(w)}<p class="mini">Sobre «${h(j.fuente.nombre)}» · ${j.dias ? `últimos ${j.dias} días` : 'todo el historial'} · ${j.registros} registros</p>
        ${kpis(j.tarjetas.map((t) => ({ t: t.titulo, n: t.valor == null ? '—' : String(t.valor).replace('.', ','), sub: t.operacion === 'contar' ? `por ${t.etiqueta.toLowerCase()}` : `${t.operacion} de ${t.etiqueta.toLowerCase()}`, icono: 'reportes', color: '#3949ab', k: 'ir' })))}</div>
        ${j.tarjetas.filter((t) => t.grupos).map((t) => { const max = Math.max(1, ...Object.values(t.grupos));
          return `<div class="tarjeta"><h2>${h(t.titulo)}</h2>${Object.entries(t.grupos).map(([k, n]) => `<div style="display:flex;align-items:center;gap:8px;margin:4px 0"><span style="flex:0 0 34%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${h(k)}</span>
            <span style="flex:1;background:#e8eef7;border-radius:6px;overflow:hidden"><i style="display:block;height:14px;width:${Math.round(100 * n / max)}%;background:#3949ab"></i></span><b style="flex:0 0 48px;text-align:right">${n}</b></div>`).join('')}</div>`; }).join('')}`;
      alClicKpi(el, () => Nucleo.ir(j.fuente.id));
    },

    async pagina(el, w) {
      const d = w.definicion;
      el.innerHTML = `${cabecera(w)}${d.texto ? `<div style="white-space:pre-wrap">${h(d.texto)}</div>` : ''}
        ${d.url ? (d.embebida ? '' : `<p><a class="b" href="${h(d.url)}" target="_blank" rel="noopener noreferrer">Abrir ${h(w.nombre)}</a></p>`) : ''}</div>
        ${d.url && d.embebida ? `<iframe src="${h(d.url)}" title="${h(w.nombre)}" referrerpolicy="no-referrer" sandbox="allow-scripts allow-same-origin allow-forms allow-popups" style="width:100%;height:calc(100vh - 220px);min-height:420px;border:1px solid #d5deea;border-radius:12px;background:#fff"></iframe>` : ''}`;
    },
  };

  // ---------------------------------------------------------------- Configuración › Widgets
  let sub = 'widgets';
  Nucleo.configuracion({ id: 'widgets', titulo: 'Widgets', render: async (c) => {
    const A = await api('GET', 'm/widgets/admin'); const acc = Nucleo.accesos; const G0 = Nucleo.grupos0;
    const gNom = (id) => A.grupos.find((g) => g.id === id)?.nombre || G0[id]?.[0] || id || '—';
    // lista completa: los accesos de los módulos (con lo que diga el catálogo) más los creados acá
    const fila = (a) => { const w = A.widgets.find((x) => x.id === a.id); return { id: a.id, nombre: w?.nombre || a.titulo, grupo: w?.grupo || a.grupo, icono: w?.icono || a.icono, orden: w?.orden ?? a.orden ?? 50, activo: w ? w.activo : 1, origen: 'modulo', enCat: !!w, base: a, descripcion: w?.descripcion || '' }; };
    const todos = [...acc.filter((a) => !a.definido).map(fila), ...A.widgets.filter((w) => w.origen === 'definido').map((w) => ({ ...w, enCat: true }))].sort((a, b) => a.orden - b.orden);
    const recargar = () => cargar(true); const pestActual = () => Nucleo.ir('configuracion');
    c.innerHTML = `<div class="subp" id="wSub">${[['widgets', `Widgets ${todos.length}`], ['grupos', `Grupos ${A.grupos.length}`], ['perfiles', `Perfiles ${A.perfiles.length}`], ['usuarios', `Usuarios ${A.usuarios.length}`], ['paquetes', 'Exportar e importar']]
      .map(([k, t]) => `<button data-s="${k}" class="${sub === k ? 'activo' : ''}">${t}</button>`).join('')}</div><div id="wC"></div>`;
    $$('#wSub button', c).forEach((b) => b.onclick = () => { sub = b.dataset.s; pestActual(); });
    const z = $('#wC', c);
    const opG = [['', '— Sin grupo (Otros) —'], ...A.grupos.map((g) => [g.id, g.nombre])];
    const opI = Nucleo.iconos.map((i) => [i, i]);

    if (sub === 'widgets') {
      const porG = {}; todos.forEach((w) => { (porG[w.grupo || 'otros'] = porG[w.grupo || 'otros'] || []).push(w); });
      const orden = [...A.grupos.map((g) => g.id), ...Object.keys(porG).filter((g) => !A.grupos.some((x) => x.id === g))].filter((g) => porG[g]);
      z.innerHTML = `<div class="tarjeta"><h2>Widgets del nodo</h2><p class="mini">Cada acceso del menú: su nombre, grupo, ícono y orden. Los que traen los módulos se pueden renombrar, mover y ocultar; los creados acá, además, se editan por dentro.
        ${A.grupos.length ? '' : ' Todavía no hay grupos propios: se ven los de fábrica. Creá grupos en la solapa «Grupos».'}</p>
        <div class="acciones"><button class="b" id="wNuevo">${icono('modulo')} Nuevo widget</button></div></div>
        ${orden.map((g) => `<div class="tarjeta"><h2>${h(gNom(g))} <small class="mini">${porG[g].length}</small></h2>${tabla([{ t: 'Widget', html: true, v: (w) => `${icono(Nucleo.iconos.includes(w.icono) ? w.icono : 'modulo')} <b>${h(w.nombre)}</b>` }, { t: 'Id', v: (w) => w.id },
          { t: 'Tipo', v: (w) => w.origen === 'definido' ? A.tipos[w.tipo] : 'Del sistema' }, { t: 'Orden', v: (w) => w.orden }, { t: 'Estado', html: true, v: (w) => w.activo ? '<span class="et verde">Activo</span>' : '<span class="et gris">Oculto</span>' }],
          porG[g], (w) => editar(w))}</div>`).join('')}`;
      $('#wNuevo', z).onclick = () => editar(null);
    }
    function editar(w) {
      const nuevo = !w; const def = w?.definicion || {};
      const base = [...(nuevo ? [{ n: 'id', t: 'Id del widget', req: true, ph: 'ej.: control_calderas', ayuda: 'Minúsculas, números y guion bajo. No se cambia después.' },
        { n: 'tipo', t: 'Qué hace', tipo: 'select', op: Object.entries(A.tipos), v: 'formulario' }] : []),
        { n: 'nombre', t: 'Nombre visible', req: true, v: w?.nombre || '' }, { n: 'grupo', t: 'Grupo', tipo: 'select', op: opG, v: A.grupos.some((g) => g.id === w?.grupo) ? w.grupo : '' },
        { n: 'orden', t: 'Orden dentro del menú', tipo: 'number', v: w?.orden ?? 50, ayuda: '10, 20, 30…' }, { n: 'icono', t: 'Ícono', tipo: 'select', op: opI, v: Nucleo.iconos.includes(w?.icono) ? w.icono : 'modulo' },
        { n: 'descripcion', t: 'Descripción', v: w?.descripcion || '', ancho: 'todo' }, { n: 'activo', t: 'Visible', tipo: 'check', v: nuevo ? true : !!w.activo, ayudaCheck: 'Se muestra en el menú' }];
      const p = panel(nuevo ? 'Nuevo widget' : `Widget «${w.nombre}»`, `${form(base, nuevo ? 'Crear y definir' : 'Guardar', async (d, m) => {
          if (nuevo) { const tipo = d.tipo; const cuerpo = { ...d, origen: 'definido', definicion: tipo === 'pagina' ? { texto: 'Página nueva' } : tipo === 'indicadores' ? null : { campos: [{ id: 'detalle', etiqueta: 'Detalle', tipo: 'area', requerido: true }], columnas: ['numero', 'creado', 'detalle'] } };
            if (tipo === 'indicadores') { const f = A.widgets.find((x) => x.origen === 'definido' && ['formulario', 'sabana'].includes(x.tipo)); if (!f) throw new Error('Para un tablero de indicadores primero creá un formulario o una sábana.');
              cuerpo.definicion = { fuente: f.id, tarjetas: [{ titulo: 'Registros', campo: 'estado', operacion: 'contar' }] }; }
            const r = await api('POST', 'm/widgets/widgets', cuerpo); cerrar(); await cargar(true); A.widgets.push(r.widget); definir(r.widget); return; }
          if (w.origen === 'definido') await api('PUT', 'm/widgets/widgets/' + w.id, d); else await api(w.enCat ? 'PUT' : 'POST', 'm/widgets/widgets' + (w.enCat ? '/' + w.id : ''), { ...d, id: w.id, origen: 'modulo' });
          aviso(m, 'ok', 'Guardado.'); setTimeout(() => { cerrar(); recargar(); }, 500);
        }, { extra: nuevo ? '' : `${w.origen === 'definido' ? '<button type="button" class="b sec" id="wDef">Definir qué hace</button><button type="button" class="b peligro" id="wBor">Borrar</button>' : (w.enCat ? '<button type="button" class="b sec" id="wRes">Volver al original</button>' : '')}` })}<div id="wMsg"></div>`);
      const bd = $('#wDef', p); if (bd) bd.onclick = () => { cerrar(); definir(w); };
      const br = $('#wRes', p); if (br) br.onclick = async () => { await api('POST', `m/widgets/widgets/${w.id}/borrar`, {}); cerrar(); recargar(); };
      const bb = $('#wBor', p); if (bb) bb.onclick = async () => { const m = $('#wMsg', p);
        try { await api('POST', `m/widgets/widgets/${w.id}/borrar`, { con_datos: bb.dataset.ok === '1' }); cerrar(); recargar(); }
        catch (e) { if (e.codigo === 'con_datos' || /registro/.test(e.message)) { bb.dataset.ok = '1'; bb.textContent = 'Sí, borrar con sus registros'; } aviso(m, 'mal', e.message); } };
    }

    // editor de la definición: se arma con controles, no con texto
    function definir(w) {
      const d = JSON.parse(JSON.stringify(w.definicion || {})); const t = w.tipo; let html = '';
      const lin = (v) => (v || []).join('\n'); const deLin = (s) => s.split('\n').map((x) => x.trim()).filter(Boolean);
      if (t === 'pagina') html = `<label>Texto</label><textarea id="dTx" rows="6">${h(d.texto || '')}</textarea><label>Dirección (https://… o /ruta del nodo)</label><input id="dUrl" value="${h(d.url || '')}"><label class="fchk"><input type="checkbox" id="dEmb" ${d.embebida ? 'checked' : ''}> Mostrarla dentro del sistema</label>`;
      else if (t === 'indicadores') { const fs = A.widgets.filter((x) => x.origen === 'definido' && ['formulario', 'sabana'].includes(x.tipo));
        html = `<label>Toma los datos de</label><select id="dFu">${fs.map((f) => `<option value="${f.id}" ${f.id === d.fuente ? 'selected' : ''}>${h(f.nombre)}</option>`).join('')}</select><label>Período (días; 0 = todo)</label><input id="dDi" inputmode="numeric" value="${h(d.dias ?? 30)}">
          <h3>Tarjetas</h3><div id="dTa"></div><button type="button" class="b sec" id="dMasT">＋ Tarjeta</button>`; }
      else html = `<label>Texto de ayuda (arriba del widget)</label><input id="dAy" value="${h(d.ayuda || '')}">
          <h3>Campos</h3><div id="dCa"></div><button type="button" class="b sec" id="dMasC">＋ Campo</button>
          <label>Estados (uno por línea; el primero es el inicial)</label><textarea id="dEs" rows="3">${h(lin(d.estados))}</textarea>
          <label>Texto del botón</label><input id="dBo" value="${h(d.boton || 'Guardar')}"><label>Prefijo del número (opcional)</label><input id="dNu" value="${h(d.numerar || '')}" maxlength="8">
          ${t === 'sabana' ? `<h3>Sábana</h3><label>Columnas</label><div id="dCo" class="wchips"></div><label>Filtros (campos de lista)</label><div id="dFi" class="wchips"></div><label>Se editan en la fila</label><div id="dEd" class="wchips"></div>
            <label class="fchk"><input type="checkbox" id="dAl" ${d.alta !== false ? 'checked' : ''}> Permite cargar registros nuevos</label><label class="fchk"><input type="checkbox" id="dDe" ${d.descarga !== false ? 'checked' : ''}> Botón de descarga CSV</label>
            <label class="fchk"><input type="checkbox" id="dIm" ${d.imprimir ? 'checked' : ''}> Botón Imprimir</label>` : ''}`;
      const p = panel(`Qué hace «${w.nombre}» · ${A.tipos[t]}`, `<div class="form wdef">${html}<div class="acciones"><button class="b" id="dGu">Guardar definición</button><button class="b sec" id="dPr">Abrir el widget</button></div><div id="dMsg"></div></div>`);
      const q = (s) => $(s, p);
      if (t === 'formulario' || t === 'sabana') {
        const pintaC = () => { q('#dCa').innerHTML = (d.campos || []).map((c, i) => `<div class="wcampo" data-i="${i}" style="border:1px solid #d5deea;border-radius:10px;padding:8px;margin:6px 0;display:grid;grid-template-columns:1fr 1fr;gap:6px">
            <input data-k="etiqueta" placeholder="Etiqueta" value="${h(c.etiqueta || '')}"><input data-k="id" placeholder="id_del_campo" value="${h(c.id || '')}">
            <select data-k="tipo">${Object.entries(A.campos).map(([k, n]) => `<option value="${k}" ${c.tipo === k ? 'selected' : ''}>${n}</option>`).join('')}</select>
            <label class="fchk"><input type="checkbox" data-k="requerido" ${c.requerido ? 'checked' : ''}> Obligatorio</label>
            ${c.tipo === 'lista' ? `<textarea data-k="opciones" rows="3" placeholder="Opciones, una por línea" style="grid-column:1/3">${h(lin(c.opciones))}</textarea>` : ''}
            ${c.tipo === 'numero' ? `<input data-k="unidad" placeholder="Unidad (°C, kg…)" value="${h(c.unidad || '')}">` : ''}
            <span style="grid-column:1/3;text-align:right"><button type="button" class="b sec" data-m="-1">Subir</button> <button type="button" class="b sec" data-m="1">Bajar</button> <button type="button" class="b peligro" data-m="0">Quitar</button></span></div>`).join('');
          $$('.wcampo', p).forEach((x) => { const i = +x.dataset.i; const c = d.campos[i];
            $$('[data-k]', x).forEach((e) => e.onchange = () => { const k = e.dataset.k; c[k] = e.type === 'checkbox' ? e.checked : k === 'opciones' ? deLin(e.value) : e.value.trim();
              if (k === 'etiqueta' && !c.id) { c.id = c.etiqueta.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').replace(/^(\d)/, 'c$1').slice(0, 40); }
              if (k === 'tipo' || k === 'etiqueta' || k === 'id') { pintaC(); pintaChips(); } });
            $$('[data-m]', x).forEach((b) => b.onclick = () => { const m = +b.dataset.m; if (!m) d.campos.splice(i, 1); else { const j = i + m; if (j < 0 || j >= d.campos.length) return; [d.campos[i], d.campos[j]] = [d.campos[j], d.campos[i]]; } pintaC(); pintaChips(); }); }); };
        const chips = (sel, lista, ops) => { const e = q(sel); if (!e) return; e.innerHTML = ops.map(([k, n]) => `<label class="fchk" style="display:inline-flex;margin:2px 10px 2px 0"><input type="checkbox" value="${h(k)}" ${lista.includes(k) ? 'checked' : ''}> ${h(n)}</label>`).join(''); };
        const pintaChips = () => { if (t !== 'sabana') return; const cs = (d.campos || []).filter((c) => c.id).map((c) => [c.id, c.etiqueta || c.id]);
          chips('#dCo', d.columnas || [], [['numero', 'N.º'], ['creado', 'Fecha'], ['creado_por', 'Cargó'], ['estado', 'Estado'], ...cs]); chips('#dFi', d.filtros || [], (d.campos || []).filter((c) => c.tipo === 'lista').map((c) => [c.id, c.etiqueta]));
          chips('#dEd', d.editables || [], [['estado', 'Estado'], ...cs]); };
        const leeChips = (sel) => $$(`${sel} input:checked`, p).map((x) => x.value);
        d.campos = d.campos || []; pintaC(); pintaChips();
        ['#dCo', '#dFi', '#dEd'].forEach((s) => { const e = q(s); if (e) e.onchange = () => { d[{ '#dCo': 'columnas', '#dFi': 'filtros', '#dEd': 'editables' }[s]] = leeChips(s); }; });
        q('#dMasC').onclick = () => { d.campos.push({ id: '', etiqueta: '', tipo: 'texto', requerido: false }); pintaC(); };
        q('#dGu').onclick = () => guardar({ ...d, ayuda: q('#dAy').value, estados: deLin(q('#dEs').value), boton: q('#dBo').value, numerar: q('#dNu').value,
          ...(t === 'sabana' ? { columnas: leeChips('#dCo'), filtros: leeChips('#dFi'), editables: leeChips('#dEd'), alta: q('#dAl').checked, descarga: q('#dDe').checked, imprimir: q('#dIm').checked } : {}) });
      } else if (t === 'indicadores') {
        const camposDe = (id) => { const f = A.widgets.find((x) => x.id === id); return [['estado', 'Estado'], ...((f?.definicion?.campos) || []).map((c) => [c.id, c.etiqueta])]; };
        const pintaT = () => { const cs = camposDe(q('#dFu').value); q('#dTa').innerHTML = (d.tarjetas || []).map((x, i) => `<div data-i="${i}" class="wtar" style="display:grid;grid-template-columns:2fr 2fr 1.4fr auto;gap:6px;margin:6px 0">
          <input data-k="titulo" placeholder="Título" value="${h(x.titulo || '')}"><select data-k="campo">${cs.map(([k, n]) => `<option value="${k}" ${x.campo === k ? 'selected' : ''}>${h(n)}</option>`).join('')}</select>
          <select data-k="operacion">${[['contar', 'Contar por valor'], ['sumar', 'Sumar'], ['promedio', 'Promedio']].map(([k, n]) => `<option value="${k}" ${x.operacion === k ? 'selected' : ''}>${n}</option>`).join('')}</select><button type="button" class="b peligro">✕</button></div>`).join('');
          $$('.wtar', p).forEach((e) => { const i = +e.dataset.i; $$('[data-k]', e).forEach((c) => c.onchange = () => { d.tarjetas[i][c.dataset.k] = c.value; }); e.querySelector('button').onclick = () => { d.tarjetas.splice(i, 1); pintaT(); }; }); };
        d.tarjetas = d.tarjetas || []; pintaT(); q('#dFu').onchange = pintaT;
        q('#dMasT').onclick = () => { d.tarjetas.push({ titulo: '', campo: 'estado', operacion: 'contar' }); pintaT(); };
        q('#dGu').onclick = () => guardar({ fuente: q('#dFu').value, dias: +q('#dDi').value || 0, tarjetas: d.tarjetas });
      } else q('#dGu').onclick = () => guardar({ texto: q('#dTx').value, url: q('#dUrl').value.trim(), embebida: q('#dEmb').checked });
      async function guardar(def) { try { const r = await api('PUT', 'm/widgets/widgets/' + w.id, { definicion: def }); w.definicion = r.widget.definicion; w.version = r.widget.version; await cargar(true); aviso(q('#dMsg'), 'ok', `Definición guardada (versión ${r.widget.version}).`); }
        catch (e) { aviso(q('#dMsg'), 'mal', e.message); } }
      q('#dPr').onclick = () => { cerrar(); Nucleo.ir(w.id); };
    }

    if (sub === 'grupos') {
      z.innerHTML = `<div class="tarjeta"><h2>Grupos del menú</h2><p class="mini">El menú y el inicio muestran los grupos en este orden, con su color. Mientras no haya ninguno se ven los de fábrica.</p>
        ${tabla([{ t: 'Grupo', html: true, v: (g) => `<i style="display:inline-block;width:12px;height:12px;border-radius:3px;background:${h(g.color)}"></i> <b>${h(g.nombre)}</b>` }, { t: 'Id', v: (g) => g.id }, { t: 'Orden', v: (g) => g.orden },
          { t: 'Widgets', v: (g) => todos.filter((w) => w.grupo === g.id).length }, { t: 'Estado', v: (g) => g.activo ? 'Activo' : 'Oculto' }], A.grupos, (g) => edG(g))}
        <div class="acciones"><button class="b" id="gNuevo">Nuevo grupo</button>${A.grupos.length ? '' : '<button class="b sec" id="gFab">Copiar los grupos de fábrica</button>'}</div><div id="gMsg"></div></div>`;
      $('#gNuevo', z).onclick = () => edG(null);
      const gf = $('#gFab', z); if (gf) gf.onclick = async () => { let o = 10; for (const [id, [nombre, color]] of Object.entries(G0)) { await api('POST', 'm/widgets/grupos', { id, nombre, color, orden: o }); o += 10; } recargar(); };
    }
    function edG(g) {
      const p = panel(g ? `Grupo «${g.nombre}»` : 'Nuevo grupo', `${form([...(g ? [] : [{ n: 'id', t: 'Id del grupo', req: true, ph: 'ej.: calderas' }]), { n: 'nombre', t: 'Nombre', req: true, v: g?.nombre || '' }, { n: 'color', t: 'Color', tipo: 'color', v: g?.color || '#1e88e5' },
        { n: 'orden', t: 'Orden', tipo: 'number', v: g?.orden ?? 50 }, { n: 'activo', t: 'Visible', tipo: 'check', v: g ? !!g.activo : true }], 'Guardar', async (d, m) => { await api(g ? 'PUT' : 'POST', 'm/widgets/grupos' + (g ? '/' + g.id : ''), d); aviso(m, 'ok', 'Guardado.'); setTimeout(() => { cerrar(); recargar(); }, 400); },
        { extra: g ? '<button type="button" class="b peligro" id="gBor">Borrar</button>' : '' })}<div id="gM2"></div>`);
      const b = $('#gBor', p); if (b) b.onclick = async () => { try { await api('POST', `m/widgets/grupos/${g.id}/borrar`, {}); cerrar(); recargar(); } catch (e) { aviso($('#gM2', p), 'mal', e.message); } };
    }

    if (sub === 'perfiles') {
      z.innerHTML = `<div class="tarjeta"><h2>Perfiles</h2><p class="mini">Perfil = qué widgets ve una persona. El rol sigue diciendo qué puede hacer dentro de cada uno. Quien no tiene perfil ve todo lo que su rol permite; el administrador ve todo siempre.</p>
        ${tabla([{ t: 'Perfil', html: true, v: (x) => `<i style="display:inline-block;width:12px;height:12px;border-radius:50%;background:${h(x.color)}"></i> <b>${h(x.nombre)}</b>` }, { t: 'Id', v: (x) => x.id }, { t: 'Widgets', v: (x) => x.widgets.length },
          { t: 'Personas', v: (x) => x.usuarios }, { t: 'Estado', v: (x) => x.activo ? 'Activo' : 'Inactivo' }], A.perfiles, (x) => edP(x))}<div class="acciones"><button class="b" id="pNuevo">Nuevo perfil</button></div></div>`;
      $('#pNuevo', z).onclick = () => edP(null);
    }
    function edP(x) {
      const sel = new Set(x?.widgets || []);
      const porG = {}; todos.forEach((w) => { (porG[w.grupo || 'otros'] = porG[w.grupo || 'otros'] || []).push(w); });
      const p = panel(x ? `Perfil «${x.nombre}»` : 'Nuevo perfil', `${form([...(x ? [] : [{ n: 'id', t: 'Id del perfil', req: true, ph: 'ej.: jefes_servicio' }]), { n: 'nombre', t: 'Nombre', req: true, v: x?.nombre || '' }, { n: 'color', t: 'Color', tipo: 'color', v: x?.color || '#1e88e5' },
        { n: 'descripcion', t: 'Descripción', v: x?.descripcion || '', ancho: 'todo' }, { n: 'activo', t: 'Perfil activo', tipo: 'check', v: x ? !!x.activo : true }], 'Guardar perfil', async (d, m) => {
          await api(x ? 'PUT' : 'POST', 'm/widgets/perfiles' + (x ? '/' + x.id : ''), { ...d, widgets: [...sel] }); aviso(m, 'ok', 'Guardado.'); setTimeout(() => { cerrar(); recargar(); }, 400); },
        { extra: x ? '<button type="button" class="b peligro" id="pBor">Borrar</button>' : '' })}<div id="pM2"></div>
        <h3>Widgets que ve <small class="mini" id="pN"></small></h3>${Object.keys(porG).map((g) => `<div style="margin:8px 0"><b>${h(gNom(g))}</b> <button type="button" class="b sec" data-g="${h(g)}">Todos</button><br>
          ${porG[g].map((w) => `<label class="fchk" style="display:inline-flex;margin:2px 10px 2px 0"><input type="checkbox" class="pW" data-g="${h(g)}" value="${h(w.id)}" ${sel.has(w.id) ? 'checked' : ''}> ${h(w.nombre)}</label>`).join('')}</div>`).join('')}`);
      const cuenta = () => { $('#pN', p).textContent = `(${sel.size} de ${todos.length})`; }; cuenta();
      $$('.pW', p).forEach((c) => c.onchange = () => { c.checked ? sel.add(c.value) : sel.delete(c.value); cuenta(); });
      $$('button[data-g]', p).forEach((b) => b.onclick = () => { const cs = $$(`.pW[data-g="${CSS.escape(b.dataset.g)}"]`, p); const on = cs.some((c) => !c.checked); cs.forEach((c) => { c.checked = on; on ? sel.add(c.value) : sel.delete(c.value); }); cuenta(); });
      const b = $('#pBor', p); if (b) b.onclick = async () => { try { await api('POST', `m/widgets/perfiles/${x.id}/borrar`, {}); cerrar(); recargar(); } catch (e) { aviso($('#pM2', p), 'mal', e.message); } };
    }

    if (sub === 'usuarios') {
      z.innerHTML = `<div class="tarjeta"><h2>Perfil de cada persona</h2><p class="mini">El alta y el rol se manejan en «Usuarios». Acá se elige qué perfil tiene cada una.</p>
        ${tabla([{ t: 'Usuario', v: (u) => u.usuario }, { t: 'Nombre', v: (u) => u.nombre }, { t: 'Rol', v: (u) => u.rol }, { t: 'Perfil', html: true, v: (u) => u.rol === 'AD' ? '<span class="mini">Ve todo</span>' :
          `<select class="uP" data-u="${h(u.usuario)}"><option value="">— Sin perfil —</option>${A.perfiles.map((x) => `<option value="${h(x.id)}" ${u.perfil === x.id ? 'selected' : ''}>${h(x.nombre)}</option>`).join('')}</select>` }], A.usuarios)}<div id="uMsg"></div></div>`;
      $$('.uP', z).forEach((s) => s.onchange = async () => { try { await api('PUT', `m/widgets/usuarios/${encodeURIComponent(s.dataset.u)}/perfil`, { perfil: s.value }); aviso($('#uMsg', z), 'ok', `Perfil de ${s.dataset.u} guardado.`); } catch (e) { aviso($('#uMsg', z), 'mal', e.message); } });
    }

    if (sub === 'paquetes') {
      const defs = A.widgets.filter((w) => w.origen === 'definido');
      z.innerHTML = `<div class="tarjeta"><h2>Exportar widgets</h2><p class="mini">Baja un archivo con la definición de los widgets elegidos (sin sus registros) para cargarlo en otro nodo o guardarlo como respaldo.</p>
        ${defs.map((w) => `<label class="fchk" style="display:inline-flex;margin:2px 10px 2px 0"><input type="checkbox" class="xW" value="${h(w.id)}" checked> ${h(w.nombre)}</label>`).join('') || '<p class="mini">Todavía no hay widgets creados.</p>'}
        <div class="acciones"><button class="b" id="xBaj" ${defs.length ? '' : 'disabled'}>${icono('bajar')} Bajar paquete</button></div></div>
        <div class="tarjeta"><h2>Importar widgets</h2><p class="mini">Carga un paquete bajado de otro nodo. Si un widget ya existe, se actualiza su definición y suma una versión.</p><input type="file" id="xArc" accept="application/json,.json"><div id="xMsg"></div></div>`;
      $('#xBaj', z).onclick = async () => { const r = await api('POST', 'm/widgets/exportar', { widgets: $$('.xW:checked', z).map((x) => x.value) });
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([JSON.stringify(r.paquete, null, 2)], { type: 'application/json' })); a.download = 'widgets-iproy.json'; document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500); };
      $('#xArc', z).onchange = async (e) => { const f = e.target.files[0]; if (!f) return; try { const r = await api('POST', 'm/widgets/importar', { paquete: JSON.parse(await f.text()) }); aviso($('#xMsg', z), 'ok', `Importados: ${r.importados.join(', ') || 'ninguno'}.`); await cargar(true); }
        catch (x) { aviso($('#xMsg', z), 'mal', x.message); } };
    }
  } });
})();
