import Sortable from 'sortablejs/modular/sortable.core.esm.js'
import qrcode from 'qrcode-generator'
import { ikonka, galochka, PUSTO_SVG } from './icons.js'
import './app.css'

const N = window.NTODO || {}
const $ = (s, r = document) => r.querySelector(s)

const MES = ['янв.', 'февр.', 'мар.', 'апр.', 'мая', 'июн.', 'июл.', 'авг.', 'сент.', 'окт.', 'нояб.', 'дек.']
const PRI = ['Без приоритета', 'Низкий', 'Средний', 'Высокий']
const REMIND = [[null, 'Не напоминать'], [0, 'В момент'], [15, 'За 15 мин'], [60, 'За 1 ч'], [1440, 'За 1 день']]
const REPEAT = [[null, 'Не повторять'], ['daily', 'Каждый день'], ['weekly', 'Каждую неделю'], ['monthly', 'Каждый месяц']]
const FILTRY = [['all', 'Все', 'list'], ['today', 'Сегодня', 'sun'], ['upcoming', 'Предстоящие', 'calendar'], ['done', 'Выполнено', 'check']]
const POLYA = ['title', 'note', 'done', 'priority', 'due_at', 'due_has_time', 'remind_before', 'repeat_rule']

const S = {
  user: N.user || null,
  tasks: [],
  filter: 'all',
  q: '',
  sel: null,
  multi: new Set(),
  openCid: null,
  editCid: null,
  undo: [],
  queue: [],
  busy: false,
  online: navigator.onLine,
  oshibka: false,
  tg: N.telegram || null,
  doneOpen: true,
  modal: null,
  sortable: null,
}

const KLUCH = S.user ? 'ntodo_cache_' + S.user.id : 'ntodo_guest'
const KLUCH_Q = S.user ? 'ntodo_queue_' + S.user.id : null
const ROWS = new Map()
let POP = null

function el(tag, props = {}, ...kids) {
  const e = document.createElement(tag)
  for (const [k, v] of Object.entries(props)) {
    if (v == null || v === false) continue
    if (k === 'class') e.className = v
    else if (k === 'text') e.textContent = v
    else if (k.startsWith('on') && typeof v === 'function') e.addEventListener(k.slice(2), v)
    else if (['value', 'checked', 'selected', 'disabled', 'hidden'].includes(k)) e[k] = v
    else e.setAttribute(k, v === true ? '' : v)
  }
  for (const c of kids.flat()) if (c != null && c !== false) e.append(c)
  return e
}

function chitat(k) {
  try {
    return JSON.parse(localStorage.getItem(k))
  } catch {
    return null
  }
}

function pisat(k, v) {
  try {
    localStorage.setItem(k, JSON.stringify(v))
  } catch {}
}

function uuid() {
  if (window.crypto?.randomUUID) return crypto.randomUUID()
  return 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10)
}

const umenshitDvizhenie = () => matchMedia('(prefers-reduced-motion: reduce)').matches
const naiti = (cid) => S.tasks.find((t) => t.cid === cid)
const poPozicii = (a, b) => a.position - b.position || String(a.cid).localeCompare(String(b.cid))
const zhivye = () => S.tasks.filter((t) => !t.deleted)

function izServera(t) {
  return {
    id: t.id,
    cid: t.client_uid || 's' + t.id,
    title: t.title,
    note: t.note || '',
    done: !!t.done,
    priority: t.priority | 0,
    position: t.position | 0,
    due_at: t.due_at || null,
    due_has_time: !!t.due_has_time,
    remind_before: t.remind_before ?? null,
    repeat_rule: t.repeat_rule || null,
    completed_at: t.completed_at || null,
    deleted: false,
  }
}

function dlyaServera(d) {
  const o = {}
  for (const k of POLYA) if (k in d) o[k] = d[k]
  return o
}

function denKey(d) {
  return d.getFullYear() * 10000 + (d.getMonth() + 1) * 100 + d.getDate()
}

function segodnyaKey() {
  return denKey(new Date())
}

function Prosrocheno(t) {
  if (!t.due_at || t.done) return false
  const d = new Date(t.due_at)
  return t.due_has_time ? d < new Date() : denKey(d) < segodnyaKey()
}

function eto_segodnya(t) {
  return !!t.due_at && (denKey(new Date(t.due_at)) <= segodnyaKey())
}

function predstoyashee(t) {
  return !!t.due_at && denKey(new Date(t.due_at)) > segodnyaKey()
}

function dataTekst(t) {
  const d = new Date(t.due_at)
  const raz = Math.round((new Date(d.getFullYear(), d.getMonth(), d.getDate()) - new Date(new Date().setHours(0, 0, 0, 0))) / 864e5)
  let s
  if (raz === 0) s = 'Сегодня'
  else if (raz === 1) s = 'Завтра'
  else if (raz === -1) s = 'Вчера'
  else {
    s = d.getDate() + ' ' + MES[d.getMonth()]
    if (d.getFullYear() !== new Date().getFullYear()) s += ' ' + d.getFullYear()
  }
  if (t.due_has_time) s += ', ' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0')
  return s
}

function ymd(d) {
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0')
}

function hm(d) {
  return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0')
}

function sohranitKesh() {
  if (S.user) {
    pisat(KLUCH, S.tasks)
    pisat(KLUCH_Q, S.queue)
  } else {
    pisat(KLUCH, S.tasks.filter((t) => !t.deleted))
  }
}

async function api(method, path, body, povtor = true) {
  let r
  try {
    r = await fetch(N.rest + path, {
      method,
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': N.nonce },
      body: body ? JSON.stringify(body) : undefined,
    })
  } catch {
    const e = new Error('Нет сети')
    e.offline = true
    throw e
  }
  if (r.status === 204) return null
  let data = null
  try {
    data = await r.json()
  } catch {}
  if (r.status === 403 && data?.code === 'rest_cookie_invalid_nonce' && povtor) {
    if (await obnovitNonce()) return api(method, path, body, false)
  }
  if (!r.ok) {
    const e = new Error(data?.message || 'Ошибка сервера. Попробуйте ещё раз.')
    e.status = r.status
    e.code = data?.code
    throw e
  }
  return data
}

async function obnovitNonce() {
  try {
    const r = await fetch(N.ajax + '?action=rest-nonce', { credentials: 'same-origin' })
    if (!r.ok) return false
    N.nonce = (await r.text()).trim()
    return !!N.nonce
  } catch {
    return false
  }
}

function postavit(op) {
  if (!S.user) return
  S.queue.push(op)
  sohranitKesh()
  Obrabotat_ochered()
}

async function vypolnitOp(op) {
  const t = naiti(op.cid)
  if (op.op === 'create') {
    if (!t || t.id) return
    const res = await api('POST', '/tasks', { ...dlyaServera(t), client_uid: t.cid })
    t.id = res.id
    return
  }
  if (op.op === 'order') {
    const ids = op.cids.map((c) => naiti(c)?.id).filter(Boolean)
    if (ids.length) await api('PUT', '/tasks/order', { ids })
    return
  }
  if (op.op === 'clear') {
    await api('DELETE', '/tasks/completed')
    return
  }
  if (!t?.id) return
  if (op.op === 'patch') {
    const res = await api('PATCH', '/tasks/' + t.id, op.data)
    if (res?.spawned && !S.tasks.some((x) => x.id === res.spawned.id)) {
      S.tasks.push(izServera(res.spawned))
      render()
    }
  } else if (op.op === 'delete') await api('DELETE', '/tasks/' + t.id)
  else if (op.op === 'restore') await api('POST', '/tasks/' + t.id + '/restore')
}

async function Obrabotat_ochered() {
  if (S.busy || !S.user) return
  S.busy = true
  obnovitStatus()
  while (S.queue.length) {
    const op = S.queue[0]
    try {
      await vypolnitOp(op)
      S.online = true
      S.oshibka = false
      S.queue.shift()
      sohranitKesh()
    } catch (e) {
      if (e.offline) {
        S.online = false
        break
      }
      if (e.status === 429) {
        await new Promise((r) => setTimeout(r, 5000))
        continue
      }
      if (e.status === 401) {
        pokazatToast('Сессия истекла. Войдите снова.', { action: 'Войти', fn: () => location.reload() })
        break
      }
      if (!e.status || e.status >= 500) {
        if (!S.oshibka) pokazatToast(e.message, { action: 'Повторить', fn: Obrabotat_ochered, time: 8000 })
        S.oshibka = true
        break
      }
      S.queue.shift()
      sohranitKesh()
      if (e.status !== 404) pokazatToast(e.message)
    }
  }
  S.busy = false
  obnovitStatus()
}

function obnovitStatus() {
  const s = $('#nt-status')
  if (!s) return
  let t, c
  if (!S.user) [t, c] = ['Сохранено в браузере', 'ok']
  else if (!S.online) [t, c] = ['Нет сети', 'off']
  else if (S.oshibka) [t, c] = ['Не сохранено', 'off']
  else if (S.busy || S.queue.length) [t, c] = ['Сохранение…', 'busy']
  else [t, c] = ['Сохранено', 'ok']
  s.textContent = t
  s.dataset.state = c
}

function izmenit(cid, data, { undo = true, delay = 0 } = {}) {
  const t = naiti(cid)
  if (!t) return
  const old = {}
  for (const k in data) old[k] = t[k]
  Object.assign(t, data)
  if (undo) zapomnit(() => izmenit(cid, old, { undo: false }))
  const dlya = dlyaServera(data)
  if (Object.keys(dlya).length) postavit({ op: 'patch', cid, data: dlya })
  sohranitKesh()
  if (delay) {
    const r = ROWS.get(cid)
    if (r) obnovitStroku(r, t)
    setTimeout(render, delay)
  } else render()
}

function zapomnit(fn) {
  S.undo.push(fn)
  if (S.undo.length > 50) S.undo.shift()
}

function otmenit() {
  const fn = S.undo.pop()
  if (!fn) return pokazatToast('Нечего отменять', { time: 2000 })
  fn()
  pokazatToast('Действие отменено', { time: 2000 })
}

function dobavit(title) {
  title = String(title).replace(/\s+/g, ' ').trim().slice(0, 500)
  if (!title) return false
  const pos = S.tasks.reduce((m, t) => Math.max(m, t.position), 0) + 1
  const t = {
    id: null,
    cid: uuid(),
    title,
    note: '',
    done: false,
    priority: 0,
    position: pos,
    due_at: null,
    due_has_time: false,
    remind_before: null,
    repeat_rule: null,
    completed_at: null,
    deleted: false,
  }
  if (S.filter === 'today' || S.filter === 'upcoming') {
    const d = new Date()
    d.setHours(0, 0, 0, 0)
    if (S.filter === 'upcoming') d.setDate(d.getDate() + 1)
    t.due_at = d.toISOString()
  }
  S.tasks.push(t)
  zapomnit(() => udalit(t.cid, { undo: false, toast: false }))
  postavit({ op: 'create', cid: t.cid })
  sohranitKesh()
  render()
  return true
}

function pereklyuchit(cid) {
  const t = naiti(cid)
  if (!t || t.deleted) return
  const done = !t.done
  izmenit(cid, { done, completed_at: done ? new Date().toISOString() : null }, { delay: umenshitDvizhenie() ? 0 : 420 })
  if (done && !S.user && t.repeat_rule) gostevoiPovtor(t)
  if (done && S.filter !== 'done' && zhivye().length && !zhivye().some((x) => !x.done)) setTimeout(konfetti, 300)
}

function gostevoiPovtor(t) {
  const d = t.due_at ? new Date(t.due_at) : new Date(new Date().setHours(0, 0, 0, 0))
  do {
    if (t.repeat_rule === 'daily') d.setDate(d.getDate() + 1)
    else if (t.repeat_rule === 'weekly') d.setDate(d.getDate() + 7)
    else d.setMonth(d.getMonth() + 1)
  } while (d < new Date())
  S.tasks.push({ ...t, cid: uuid(), id: null, done: false, completed_at: null, due_at: d.toISOString() })
}

function udalit(cid, { undo = true, toast = true } = {}) {
  const t = naiti(cid)
  if (!t || t.deleted) return
  if (S.sel === cid) S.sel = sosed(cid)
  t.deleted = true
  S.multi.delete(cid)
  if (S.openCid === cid) zakrytPanel()
  postavit({ op: 'delete', cid })
  sohranitKesh()
  const nazad = () => vosstanovit(cid)
  if (undo) zapomnit(nazad)
  if (toast) {
    pokazatToast('Задача удалена', {
      action: 'Отменить',
      fn: () => {
        S.undo = S.undo.filter((f) => f !== nazad)
        nazad()
      },
    })
  }
  const r = ROWS.get(cid)
  if (r?.isConnected && !umenshitDvizhenie()) {
    r.classList.add('nt-row--out')
    setTimeout(render, 180)
  } else render()
}

function vosstanovit(cid) {
  const t = naiti(cid)
  if (!t || !t.deleted) return
  t.deleted = false
  postavit({ op: 'restore', cid })
  sohranitKesh()
  render()
}

function ochistitVypolnennye() {
  const cids = zhivye().filter((t) => t.done).map((t) => t.cid)
  if (!cids.length) return
  for (const c of cids) naiti(c).deleted = true
  postavit({ op: 'clear', cids })
  sohranitKesh()
  const nazad = () => cids.forEach(vosstanovit)
  zapomnit(nazad)
  pokazatToast(`Удалено выполненных: ${cids.length}`, {
    action: 'Отменить',
    fn: () => {
      S.undo = S.undo.filter((f) => f !== nazad)
      nazad()
    },
  })
  render()
}

function sohranitPoryadok(cids) {
  cids.forEach((c, i) => {
    const t = naiti(c)
    if (t) t.position = i + 1
  })
  postavit({ op: 'order', cids })
  sohranitKesh()
  render()
}

function sosed(cid) {
  const v = vidimyeVse()
  const i = v.findIndex((t) => t.cid === cid)
  return (v[i + 1] || v[i - 1] || {}).cid || null
}

function vidimye() {
  const q = S.q.trim().toLowerCase()
  let list = zhivye()
  if (q) list = list.filter((t) => t.title.toLowerCase().includes(q) || (t.note || '').toLowerCase().includes(q))
  const filtr = S.filter === 'today' ? eto_segodnya : S.filter === 'upcoming' ? predstoyashee : () => true
  const act = S.filter === 'done' ? [] : list.filter((t) => !t.done && filtr(t)).sort(poPozicii)
  const done =
    S.filter === 'all' || S.filter === 'done'
      ? list.filter((t) => t.done).sort((a, b) => String(b.completed_at || '').localeCompare(String(a.completed_at || '')))
      : []
  return { act, done }
}

function vidimyeVse() {
  const { act, done } = vidimye()
  return S.doneOpen || S.filter === 'done' ? act.concat(done) : act
}

function schetchiki() {
  const z = zhivye()
  const a = z.filter((t) => !t.done)
  return {
    all: a.length,
    today: a.filter(eto_segodnya).length,
    upcoming: a.filter(predstoyashee).length,
    done: z.length - a.length,
  }
}

function sozdatStroku(t) {
  const r = el('div', { class: 'nt-row', role: 'listitem', tabindex: '-1' })
  r.dataset.cid = t.cid
  const cb = el('input', { type: 'checkbox', class: 'nt-check__inp' })
  const txt = el('span', { class: 'nt-text', spellcheck: 'false' })
  const tag = el('button', { type: 'button', class: 'nt-tag' })
  const date = el('button', { type: 'button', class: 'nt-date' })
  const bell = el('span', { class: 'nt-bell', role: 'img', 'aria-label': 'Есть напоминание' }, ikonka('bell', 14))
  const inner = el(
    'div',
    { class: 'nt-row__inner' },
    el('button', { type: 'button', class: 'nt-grip', 'aria-label': 'Перетащить задачу', tabindex: '-1' }, ikonka('grip', 14)),
    el('label', { class: 'nt-check' }, cb, el('span', { class: 'nt-check__box', 'aria-hidden': 'true' }, galochka())),
    el('div', { class: 'nt-tw' }, txt),
    el('div', { class: 'nt-row__meta' }, tag, date, bell),
    el('button', { type: 'button', class: 'nt-ibtn nt-row__del', 'aria-label': 'Удалить задачу' }, ikonka('trash')),
    el('button', { type: 'button', class: 'nt-ibtn nt-row__more', 'aria-label': 'Действия с задачей', 'aria-haspopup': 'menu' }, ikonka('more')),
  )
  r.append(inner)
  r._ = { cb, txt, tag, date, bell, inner }
  return r
}

function obnovitStroku(r, t) {
  const u = r._
  r.classList.remove('nt-row--out')
  u.cb.checked = t.done
  u.cb.setAttribute('aria-label', (t.done ? 'Вернуть в работу: ' : 'Отметить выполненной: ') + t.title)
  if (S.editCid !== t.cid && u.txt.textContent !== t.title) u.txt.textContent = t.title
  r.classList.toggle('nt-row--done', t.done)
  r.classList.toggle('nt-row--sel', S.sel === t.cid)
  r.classList.toggle('nt-row--multi', S.multi.has(t.cid))

  u.tag.hidden = !t.priority
  if (t.priority) {
    u.tag.className = 'nt-tag nt-tag--p' + t.priority
    u.tag.textContent = PRI[t.priority]
    u.tag.setAttribute('aria-label', 'Приоритет: ' + PRI[t.priority])
  }
  u.date.hidden = !t.due_at
  if (t.due_at) {
    u.date.textContent = dataTekst(t)
    u.date.classList.toggle('nt-date--late', Prosrocheno(t))
    u.date.setAttribute('aria-label', 'Срок: ' + dataTekst(t) + (Prosrocheno(t) ? ', просрочено' : ''))
  }
  u.bell.hidden = !(t.due_at && t.remind_before != null)
}

function syncSpisok(box, items) {
  const want = items.map((t) => {
    let r = ROWS.get(t.cid)
    if (!r) {
      r = sozdatStroku(t)
      ROWS.set(t.cid, r)
    }
    if (r.parentNode !== box && !umenshitDvizhenie()) r.classList.add('nt-row--in')
    obnovitStroku(r, t)
    return r
  })
  const nuzhny = new Set(want)
  for (const ch of [...box.children]) if (!nuzhny.has(ch)) ch.remove()
  want.forEach((r, i) => {
    if (box.children[i] !== r) box.insertBefore(r, box.children[i] || null)
  })
}

function render() {
  const { act, done } = vidimye()
  syncSpisok($('#nt-list'), act)
  syncSpisok($('#nt-done-list'), done)

  const sek = $('#nt-done')
  sek.hidden = !done.length
  const otkryto = S.doneOpen || S.filter === 'done'
  $('#nt-done-list').hidden = !otkryto
  const head = $('#nt-done-head')
  head.replaceChildren(ikonka('chevron', 14), el('span', { text: 'Выполнено' }), el('span', { class: 'nt-count', text: String(done.length) }))
  head.setAttribute('aria-expanded', otkryto ? 'true' : 'false')
  head.classList.toggle('is-closed', !otkryto)

  $('#nt-new').hidden = S.filter === 'done'
  renderPusto(act, done)
  renderFiltry()
  renderProgress()
  renderBulk()
  obnovitStatus()
  if (S.sortable) S.sortable.option('disabled', S.filter !== 'all' || !!S.q.trim() || S.multi.size > 0)
  if (S.openCid) obnovitPanel()
}

function renderPusto(act, done) {
  const box = $('#nt-empty')
  const vse = zhivye()
  let tip = null
  if (S.q.trim() && !act.length && !done.length) tip = ['Ничего не найдено', 'Попробуйте другой запрос']
  else if (S.filter === 'all' && !vse.length) tip = ['Задач пока нет', matchMedia('(pointer: coarse)').matches ? 'Нажмите «+», чтобы добавить' : 'Нажмите N, чтобы добавить']
  else if (S.filter === 'all' && !act.length) tip = ['Все задачи сделаны 🎉', '']
  else if (S.filter === 'done' && !done.length) tip = ['Выполненных задач пока нет', '']
  else if (S.filter !== 'all' && S.filter !== 'done' && !act.length) tip = ['Здесь пока пусто', 'Нажмите N, чтобы добавить задачу']
  box.hidden = !tip
  if (!tip) return
  const pic = el('div', { class: 'nt-empty__pic' })
  pic.innerHTML = PUSTO_SVG
  box.replaceChildren(pic, el('p', { class: 'nt-empty__title', text: tip[0] }), tip[1] ? el('p', { class: 'nt-empty__sub', text: tip[1] }) : null)
}

function postroitFiltry() {
  const nav = $('#nt-filters')
  for (const [k, t, i] of FILTRY) {
    const b = el(
      'button',
      { type: 'button', class: 'nt-side__item', onclick: () => ustanovitFiltr(k) },
      ikonka(i),
      el('span', { class: 'nt-side__label', text: t }),
      el('span', { class: 'nt-count' }),
    )
    b.dataset.f = k
    nav.append(b)
  }
}

function renderFiltry() {
  const c = schetchiki()
  for (const b of $('#nt-filters').children) {
    const on = b.dataset.f === S.filter
    b.classList.toggle('is-active', on)
    if (on) b.setAttribute('aria-current', 'page')
    else b.removeAttribute('aria-current')
    b.lastChild.textContent = c[b.dataset.f] || ''
  }
}

function ustanovitFiltr(k) {
  S.filter = k
  S.multi.clear()
  try {
    sessionStorage.setItem('ntodo_filter', k)
  } catch {}
  $('#ntodo').classList.remove('nt-app--side-open')
  render()
}

function renderProgress() {
  const z = zhivye()
  const d = z.filter((t) => t.done).length
  $('#nt-progress-text').textContent = z.length ? `Выполнено ${d} из ${z.length}` : 'Пока без задач'
  $('#nt-progress-bar').style.width = z.length ? (d / z.length) * 100 + '%' : '0'
}

function renderBulk() {
  const b = $('#nt-bulk')
  b.hidden = !S.multi.size
  if (!S.multi.size) return
  const cids = [...S.multi]
  b.replaceChildren(
    el('span', { text: `Выбрано: ${cids.length}` }),
    el('button', {
      type: 'button',
      class: 'nt-btn',
      text: 'Выполнить',
      onclick: () => {
        cids.forEach((c) => {
          const t = naiti(c)
          if (t && !t.done) izmenit(c, { done: true, completed_at: new Date().toISOString() })
        })
        S.multi.clear()
        render()
      },
    }),
    el('button', {
      type: 'button',
      class: 'nt-btn nt-btn--danger',
      text: 'Удалить',
      onclick: () => {
        cids.forEach((c) => udalit(c, { toast: false }))
        S.multi.clear()
        pokazatToast(`Удалено задач: ${cids.length}`, { action: 'Отменить', fn: () => cids.forEach(vosstanovit) })
        render()
      },
    }),
    el('button', {
      type: 'button',
      class: 'nt-link',
      text: 'Снять выделение',
      onclick: () => {
        S.multi.clear()
        render()
      },
    }),
  )
}

function postroitNovuyu() {
  const box = $('#nt-new')
  const btn = el('button', { type: 'button', class: 'nt-new__btn', onclick: otkrytVvod }, ikonka('plus'), el('span', { text: 'Новая задача' }))
  const inp = el('input', {
    type: 'text',
    class: 'nt-new__inp',
    id: 'nt-new-inp',
    maxlength: '500',
    placeholder: 'Введите задачу и нажмите Enter',
    'aria-label': 'Текст новой задачи',
    autocomplete: 'off',
    hidden: true,
  })
  inp.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.isComposing) {
      e.preventDefault()
      if (dobavit(inp.value)) inp.value = ''
      else inp.value = inp.value.trim()
      inp.scrollIntoView({ block: 'nearest' })
    } else if (e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      zakrytVvod()
    }
  })
  inp.addEventListener('blur', () => {
    if (!inp.value.trim()) zakrytVvod()
  })
  box.append(btn, inp)
}

function otkrytVvod() {
  if (S.filter === 'done') ustanovitFiltr('all')
  const inp = $('#nt-new-inp')
  $('.nt-new__btn').hidden = true
  inp.hidden = false
  inp.focus()
  inp.scrollIntoView({ block: 'nearest' })
}

function zakrytVvod() {
  const inp = $('#nt-new-inp')
  inp.value = ''
  inp.hidden = true
  $('.nt-new__btn').hidden = false
}

function nachatRedakt(cid) {
  const r = ROWS.get(cid)
  const t = naiti(cid)
  if (!r || !t || S.editCid === cid) return
  S.editCid = cid
  S.sel = cid
  const txt = r._.txt
  try {
    txt.contentEditable = 'plaintext-only'
  } catch {
    txt.contentEditable = 'true'
  }
  if (txt.contentEditable !== 'plaintext-only') txt.contentEditable = 'true'
  txt.setAttribute('role', 'textbox')
  txt.setAttribute('aria-label', 'Текст задачи')
  txt.focus()
  const range = document.createRange()
  range.selectNodeContents(txt)
  range.collapse(false)
  const sel = getSelection()
  sel.removeAllRanges()
  sel.addRange(range)
}

function zakonchitRedakt(cid, otmena = false) {
  const r = ROWS.get(cid)
  const t = naiti(cid)
  if (!r || S.editCid !== cid) return
  S.editCid = null
  const txt = r._.txt
  txt.contentEditable = 'false'
  txt.removeAttribute('role')
  txt.removeAttribute('aria-label')
  const v = txt.textContent.replace(/\s+/g, ' ').trim().slice(0, 500)
  if (otmena || !v || !t || v === t.title) {
    txt.textContent = t ? t.title : ''
    return
  }
  izmenit(cid, { title: v })
}

function zakrytPop(fokus = true) {
  if (!POP) return
  const a = POP.anchor
  POP.el.remove()
  POP = null
  if (fokus && a?.isConnected) a.focus({ preventScroll: true })
}

function otkrytPop(anchor, content, xy) {
  zakrytPop(false)
  const p = el('div', { class: 'nt-pop' }, content)
  $('#ntodo').append(p)
  const rect = anchor.getBoundingClientRect()
  let x = xy ? xy.x : rect.left
  let y = xy ? xy.y : rect.bottom + 4
  const w = p.offsetWidth
  const h = p.offsetHeight
  if (x + w > innerWidth - 8) x = Math.max(8, innerWidth - w - 8)
  if (y + h > innerHeight - 8) y = Math.max(8, (xy ? y : rect.top - 4) - h)
  p.style.left = x + 'px'
  p.style.top = y + 'px'
  POP = { el: p, anchor }
  const first = p.querySelector('button, input, select')
  if (first) first.focus({ preventScroll: true })
  return p
}

function vybor(opts, cur, label) {
  const s = el('select', { class: 'nt-inp', 'aria-label': label })
  for (const [v, t] of opts) s.append(el('option', { value: v == null ? '' : String(v), text: t, selected: (cur ?? null) === v }))
  return s
}

function izVybora(s, chislo) {
  if (s.value === '') return null
  return chislo ? Number(s.value) : s.value
}

function podskazkaTG() {
  if (!S.user) return 'Войдите и подключите Telegram, чтобы получать напоминания'
  if (!S.tg?.linked && N.botReady) return 'Подключите Telegram, чтобы получать напоминания'
  return ''
}

function popSrok(cid, anchor) {
  const t = naiti(cid)
  if (!t) return
  const d = t.due_at ? new Date(t.due_at) : null
  const date = el('input', { type: 'date', class: 'nt-inp', value: d ? ymd(d) : '', 'aria-label': 'Дата' })
  const time = el('input', { type: 'time', class: 'nt-inp', value: d && t.due_has_time ? hm(d) : '', 'aria-label': 'Время' })
  const rem = vybor(REMIND, t.remind_before, 'Напомнить')
  const hint = el('p', { class: 'nt-hint' })

  const primenit = () => {
    if (!date.value) {
      izmenit(cid, { due_at: null, due_has_time: false, remind_before: null })
      time.value = ''
      rem.value = ''
    } else {
      const [y, m, dd] = date.value.split('-').map(Number)
      let dt
      if (time.value) {
        const [h, mi] = time.value.split(':').map(Number)
        dt = new Date(y, m - 1, dd, h, mi)
      } else dt = new Date(y, m - 1, dd)
      izmenit(cid, { due_at: dt.toISOString(), due_has_time: !!time.value, remind_before: izVybora(rem, true) })
    }
    rem.disabled = !date.value
    hint.textContent = rem.value !== '' ? podskazkaTG() : ''
  }
  const bystro = (dni) => () => {
    const x = new Date()
    x.setDate(x.getDate() + dni)
    date.value = ymd(x)
    primenit()
  }

  rem.disabled = !date.value
  date.addEventListener('change', primenit)
  time.addEventListener('change', primenit)
  rem.addEventListener('change', primenit)

  otkrytPop(
    anchor,
    el(
      'div',
      { class: 'nt-dp', role: 'dialog', 'aria-label': 'Срок и напоминание' },
      el(
        'div',
        { class: 'nt-dp__quick' },
        el('button', { type: 'button', class: 'nt-chip', text: 'Сегодня', onclick: bystro(0) }),
        el('button', { type: 'button', class: 'nt-chip', text: 'Завтра', onclick: bystro(1) }),
        el('button', { type: 'button', class: 'nt-chip', text: 'След. неделя', onclick: bystro(7) }),
      ),
      el('label', { class: 'nt-field' }, el('span', { text: 'Дата' }), date),
      el('label', { class: 'nt-field' }, el('span', { text: 'Время' }), time),
      el('label', { class: 'nt-field' }, el('span', { text: 'Напомнить' }), rem),
      hint,
      el(
        'div',
        { class: 'nt-dp__foot' },
        el('button', {
          type: 'button',
          class: 'nt-link',
          text: 'Убрать срок',
          onclick: () => {
            date.value = ''
            primenit()
          },
        }),
        el('button', { type: 'button', class: 'nt-btn nt-btn--primary', text: 'Готово', onclick: () => zakrytPop() }),
      ),
    ),
  )
}

function popPrioritet(cid, anchor) {
  const t = naiti(cid)
  if (!t) return
  otkrytPop(
    anchor,
    el(
      'div',
      { class: 'nt-menu', role: 'menu', 'aria-label': 'Приоритет' },
      PRI.map((name, v) =>
        el(
          'button',
          {
            type: 'button',
            class: 'nt-menu__item' + (t.priority === v ? ' is-active' : ''),
            role: 'menuitemradio',
            'aria-checked': t.priority === v ? 'true' : 'false',
            onclick: () => {
              zakrytPop()
              if (t.priority !== v) izmenit(cid, { priority: v })
            },
          },
          v ? el('span', { class: 'nt-tag nt-tag--p' + v, text: name }) : el('span', { text: name }),
        ),
      ),
    ),
  )
}

function menuZadachi(cid, anchor, xy) {
  const t = naiti(cid)
  if (!t) return
  const punkt = (text, icon, fn, cls = '') =>
    el(
      'button',
      {
        type: 'button',
        class: 'nt-menu__item ' + cls,
        role: 'menuitem',
        onclick: () => {
          zakrytPop(false)
          fn()
        },
      },
      ikonka(icon),
      el('span', { text }),
    )
  otkrytPop(
    anchor,
    el(
      'div',
      { class: 'nt-menu', role: 'menu', 'aria-label': 'Действия' },
      punkt('Открыть', 'open', () => otkrytPanel(cid)),
      punkt('Редактировать', 'note', () => nachatRedakt(cid)),
      punkt('Срок и напоминание', 'calendar', () => popSrok(cid, anchor)),
      punkt('Приоритет', 'flag', () => popPrioritet(cid, anchor)),
      punkt(t.done ? 'Вернуть в работу' : 'Выполнить', 'check', () => pereklyuchit(cid)),
      el('div', { class: 'nt-menu__sep', role: 'separator' }),
      punkt('Удалить', 'trash', () => udalit(cid), 'is-danger'),
    ),
    xy,
  )
}

function otkrytPanel(cid) {
  const t = naiti(cid)
  if (!t) return
  S.openCid = cid
  S.sel = cid
  const p = $('#nt-panel')
  const zakr = el('button', { type: 'button', class: 'nt-ibtn', 'aria-label': 'Закрыть панель', onclick: zakrytPanel }, ikonka('x'))
  const title = el('textarea', { class: 'nt-panel__title', rows: '1', maxlength: '500', 'aria-label': 'Название задачи' })
  const status = el('input', { type: 'checkbox', class: 'nt-panel__cb', id: 'nt-p-status' })
  const statusLbl = el('label', { for: 'nt-p-status', class: 'nt-panel__status' })
  const srok = el('button', { type: 'button', class: 'nt-prop__btn' })
  const rem = vybor(REMIND, t.remind_before, 'Напомнить')
  const pri = vybor(PRI.map((n, i) => [i, n]), t.priority, 'Приоритет')
  const rep = vybor(REPEAT, t.repeat_rule, 'Повтор')
  const hint = el('p', { class: 'nt-hint' })
  const note = el('textarea', { class: 'nt-panel__note', placeholder: 'Добавьте заметку…', 'aria-label': 'Заметка', maxlength: '10000' })

  const rost = () => {
    title.style.height = 'auto'
    title.style.height = title.scrollHeight + 'px'
  }
  title.addEventListener('input', rost)
  title.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault()
      title.blur()
    }
  })
  title.addEventListener('blur', () => {
    const v = title.value.replace(/\s+/g, ' ').trim().slice(0, 500)
    const tt = naiti(S.openCid)
    if (!tt) return
    if (v && v !== tt.title) izmenit(tt.cid, { title: v })
    else title.value = tt.title
  })
  status.addEventListener('change', () => pereklyuchit(S.openCid))
  srok.addEventListener('click', () => popSrok(S.openCid, srok))
  rem.addEventListener('change', () => izmenit(S.openCid, { remind_before: izVybora(rem, true) }))
  pri.addEventListener('change', () => izmenit(S.openCid, { priority: Number(pri.value) }))
  rep.addEventListener('change', () => izmenit(S.openCid, { repeat_rule: izVybora(rep) }))
  let tmr
  const sohrZametku = () => {
    clearTimeout(tmr)
    const tt = naiti(S.openCid)
    if (tt && note.value !== tt.note) izmenit(tt.cid, { note: note.value }, { undo: false })
  }
  note.addEventListener('input', () => {
    clearTimeout(tmr)
    tmr = setTimeout(sohrZametku, 800)
  })
  note.addEventListener('blur', sohrZametku)

  const prop = (icon, name, ...val) => el('div', { class: 'nt-prop' }, el('div', { class: 'nt-prop__name' }, ikonka(icon), el('span', { text: name })), el('div', { class: 'nt-prop__val' }, ...val))

  p.replaceChildren(
    el(
      'div',
      { class: 'nt-panel__head' },
      zakr,
      el('span', { class: 'nt-panel__crumb', text: 'Мои задачи' }),
      el('button', { type: 'button', class: 'nt-ibtn', 'aria-label': 'Удалить задачу', onclick: () => udalit(S.openCid) }, ikonka('trash')),
    ),
    el(
      'div',
      { class: 'nt-panel__body' },
      title,
      el(
        'div',
        { class: 'nt-props' },
        prop('status', 'Статус', el('span', { class: 'nt-panel__statuswrap' }, status, statusLbl)),
        prop('calendar', 'Срок', srok),
        prop('bell', 'Напомнить', rem),
        prop('flag', 'Приоритет', pri),
        prop('repeat', 'Повтор', rep),
      ),
      hint,
      el('div', { class: 'nt-panel__sep' }),
      note,
    ),
  )
  p._ = { title, status, statusLbl, srok, rem, pri, rep, hint, note, rost }
  p.removeAttribute('inert')
  $('#ntodo').classList.add('nt-app--panel')
  obnovitPanel(true)
  render()
  requestAnimationFrame(() => {
    rost()
    zakr.focus({ preventScroll: true })
  })
}

function obnovitPanel(vse = false) {
  const p = $('#nt-panel')
  const t = naiti(S.openCid)
  if (!t || t.deleted || !p._) return zakrytPanel()
  const u = p._
  const f = document.activeElement
  if (vse || f !== u.title) u.title.value = t.title
  if (vse || f !== u.note) u.note.value = t.note || ''
  u.status.checked = t.done
  u.statusLbl.textContent = t.done ? 'Выполнено' : 'Не выполнено'
  u.statusLbl.classList.toggle('is-done', t.done)
  u.srok.replaceChildren(t.due_at ? el('span', { class: Prosrocheno(t) ? 'nt-date--late' : '', text: dataTekst(t) }) : el('span', { class: 'nt-muted', text: 'Не указан' }))
  u.rem.value = t.remind_before == null ? '' : String(t.remind_before)
  u.rem.disabled = !t.due_at
  u.pri.value = String(t.priority)
  u.rep.value = t.repeat_rule || ''
  u.hint.textContent = t.due_at && t.remind_before != null ? podskazkaTG() : ''
  u.rost()
}

function zakrytPanel() {
  if (!S.openCid) return
  const cid = S.openCid
  S.openCid = null
  const p = $('#nt-panel')
  p.setAttribute('inert', '')
  $('#ntodo').classList.remove('nt-app--panel')
  const r = ROWS.get(cid)
  if (r?.isConnected) r.focus({ preventScroll: true })
}

function pokazatToast(msg, { action, fn, time = 5000 } = {}) {
  const box = $('#nt-toasts')
  const t = el('div', { class: 'nt-toast' }, el('span', { class: 'nt-toast__msg', text: msg }))
  let timer
  const ubrat = () => {
    clearTimeout(timer)
    t.classList.add('nt-toast--out')
    setTimeout(() => t.remove(), umenshitDvizhenie() ? 0 : 180)
  }
  if (action) {
    t.append(
      el('button', {
        type: 'button',
        class: 'nt-toast__btn',
        text: action,
        onclick: () => {
          ubrat()
          fn()
        },
      }),
    )
  }
  t.append(el('button', { type: 'button', class: 'nt-toast__x', 'aria-label': 'Закрыть уведомление', onclick: ubrat }, ikonka('x', 14)))
  t.addEventListener('mouseenter', () => clearTimeout(timer))
  t.addEventListener('mouseleave', () => (timer = setTimeout(ubrat, 2000)))
  box.append(t)
  while (box.children.length > 3) box.firstChild.remove()
  timer = setTimeout(ubrat, time)
}

function modal(title, body, { onClose } = {}) {
  if (S.modal) S.modal.close()
  const prev = document.activeElement
  const wrap = el('div', { class: 'nt-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title })
  function close() {
    wrap.remove()
    S.modal = null
    if (onClose) onClose()
    if (prev?.isConnected) prev.focus({ preventScroll: true })
  }
  const card = el(
    'div',
    { class: 'nt-modal__card' },
    el('div', { class: 'nt-modal__head' }, el('h2', { text: title }), el('button', { type: 'button', class: 'nt-ibtn', 'aria-label': 'Закрыть', onclick: close }, ikonka('x'))),
    body,
  )
  wrap.append(card)
  wrap.addEventListener('pointerdown', (e) => {
    if (e.target === wrap) close()
  })
  $('#ntodo').append(wrap)
  S.modal = { close, el: wrap }
  requestAnimationFrame(() => (card.querySelector('input:not([tabindex="-1"]), .nt-btn--primary, a') || card.querySelector('button'))?.focus())
  return close
}

function spravka() {
  const k = [
    ['N', 'новая задача'],
    ['↑ / ↓', 'перемещение по списку'],
    ['Space / X', 'отметить выполненной'],
    ['Enter', 'редактировать'],
    ['Delete', 'удалить'],
    ['Shift + клик', 'выделить несколько'],
    ['Ctrl/⌘ + Z', 'отменить последнее действие'],
    ['Ctrl/⌘ + K', 'поиск'],
    ['Esc', 'закрыть / отменить'],
    ['?', 'эта справка'],
  ]
  modal('Горячие клавиши', el('table', { class: 'nt-keys' }, el('tbody', {}, k.map(([a, b]) => el('tr', {}, el('td', {}, el('kbd', { text: a })), el('td', { text: b }))))))
}

function oknoVhoda(rezhim = 'login') {
  const form = el('form', { class: 'nt-form', novalidate: true })
  const err = el('p', { class: 'nt-form__err', role: 'alert' })
  let close

  const pole = (label, attrs) => el('label', { class: 'nt-field nt-field--col' }, el('span', { text: label }), el('input', { class: 'nt-inp', required: true, ...attrs }))

  function postroit() {
    const tabs = N.canRegister
      ? el(
          'div',
          { class: 'nt-tabs', role: 'tablist' },
          ['login', 'register'].map((r) =>
            el('button', {
              type: 'button',
              role: 'tab',
              class: 'nt-tab' + (rezhim === r ? ' is-active' : ''),
              'aria-selected': rezhim === r ? 'true' : 'false',
              text: r === 'login' ? 'Вход' : 'Регистрация',
              onclick: () => {
                rezhim = r
                postroit()
              },
            }),
          ),
        )
      : null
    const polya =
      rezhim === 'login'
        ? [
            pole('Логин или email', { name: 'login', autocomplete: 'username' }),
            pole('Пароль', { name: 'password', type: 'password', autocomplete: 'current-password' }),
            el('label', { class: 'nt-check-line' }, el('input', { type: 'checkbox', name: 'remember', checked: true }), el('span', { text: 'Запомнить меня' })),
          ]
        : [
            pole('Логин', { name: 'login', autocomplete: 'username', minlength: '3' }),
            pole('Email', { name: 'email', type: 'email', autocomplete: 'email' }),
            pole('Пароль (от 8 символов)', { name: 'password', type: 'password', autocomplete: 'new-password', minlength: '8' }),
            el('input', { class: 'nt-hp', type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }),
          ]
    form.replaceChildren(
      ...[tabs, ...polya, err, el('button', { type: 'submit', class: 'nt-btn nt-btn--primary nt-btn--wide', text: rezhim === 'login' ? 'Войти' : 'Создать аккаунт' })].filter(Boolean),
      zhivye().length ? el('p', { class: 'nt-muted nt-small', text: 'Задачи из гостевого режима перенесутся в аккаунт.' }) : '',
    )
    err.textContent = ''
    requestAnimationFrame(() => form.querySelector('input.nt-inp')?.focus())
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault()
    const btn = form.querySelector('[type=submit]')
    const d = Object.fromEntries(new FormData(form))
    d.remember = !!d.remember
    err.textContent = ''
    btn.disabled = true
    try {
      await api('POST', rezhim === 'login' ? '/auth/login' : '/auth/register', d)
      location.reload()
    } catch (x) {
      err.textContent = x.offline ? 'Нет соединения с сервером' : x.message
      btn.disabled = false
    }
  })

  postroit()
  close = modal(rezhim === 'login' ? 'Вход' : 'Регистрация', form)
  return close
}

function renderPolzovatel() {
  const box = $('#nt-user')
  const imya = S.user ? S.user.name : 'Гость'
  const ava = el('span', { class: 'nt-ava', 'aria-hidden': 'true', text: imya.trim().charAt(0).toUpperCase() || '?' })
  if (S.user) {
    box.replaceChildren(
      ava,
      el('span', { class: 'nt-user__name', text: imya }),
      el(
        'button',
        {
          type: 'button',
          class: 'nt-ibtn',
          'aria-label': 'Выйти из аккаунта',
          title: 'Выйти',
          onclick: async () => {
            try {
              await api('POST', '/auth/logout')
            } catch {}
            location.reload()
          },
        },
        ikonka('logout'),
      ),
    )
  } else {
    box.replaceChildren(
      ava,
      el('span', { class: 'nt-user__name', text: imya }),
      el('button', { type: 'button', class: 'nt-btn nt-btn--small', onclick: () => oknoVhoda('login') }, ikonka('login', 14), el('span', { text: 'Войти' })),
    )
  }
}

function renderTG() {
  const box = $('#nt-tg')
  box.replaceChildren()
  if (!S.user) {
    box.append(
      el('button', { type: 'button', class: 'nt-tg__hint', onclick: () => oknoVhoda('login') }, ikonka('send', 14), el('span', { text: 'Войдите, чтобы получать напоминания в Telegram' })),
    )
    return
  }
  if (!N.botReady) return
  const tg = S.tg || {}
  if (tg.linked) {
    box.append(
      el('div', { class: 'nt-tg__ok' }, ikonka('send', 14), el('span', { text: '✓ Telegram подключён' + (tg.username ? ' @' + tg.username : '') })),
      el('button', { type: 'button', class: 'nt-link', text: 'Отключить', onclick: otklyuchitTG }),
    )
    return
  }
  if (tg.blocked) box.append(el('p', { class: 'nt-tg__warn', text: 'Бот заблокирован в Telegram — напоминания не приходят. Подключите заново.' }))
  box.append(el('button', { type: 'button', class: 'nt-side__item', onclick: podklyuchitTG }, ikonka('send'), el('span', { class: 'nt-side__label', text: 'Подключить Telegram' })))
}

async function podklyuchitTG() {
  let res
  try {
    res = await api('POST', '/telegram/link')
  } catch (e) {
    pokazatToast(e.message)
    return
  }
  const body = el('div', { class: 'nt-tgm' })
  body.append(el('p', { text: 'Откройте бота и нажмите «Запустить». Ссылка действует 15 минут.' }))
  body.append(el('a', { class: 'nt-btn nt-btn--primary nt-btn--wide', href: res.url, target: '_blank', rel: 'noopener' }, ikonka('send'), el('span', { text: 'Открыть Telegram' })))
  if (matchMedia('(min-width: 768px) and (pointer: fine)').matches) {
    const qr = qrcode(0, 'M')
    qr.addData(res.url)
    qr.make()
    const size = qr.getModuleCount() * 5 + 20
    body.append(el('p', { class: 'nt-muted nt-small', text: 'Или отсканируйте QR-код телефоном:' }))
    body.append(el('img', { class: 'nt-qr', src: qr.createDataURL(5, 10), alt: 'QR-код для подключения бота', width: String(size), height: String(size) }))
  }
  body.append(el('p', { class: 'nt-muted nt-wait', text: 'Ожидаем подтверждения в Telegram…' }))

  let close
  const timer = setInterval(async () => {
    try {
      const s = await api('GET', '/telegram/status')
      if (s.linked) {
        S.tg = s
        renderTG()
        render()
        if (close) close()
        pokazatToast('Telegram подключён' + (s.username ? ' @' + s.username : ''))
      }
    } catch {}
  }, 3000)
  close = modal('Подключить Telegram', body, { onClose: () => clearInterval(timer) })
}

async function otklyuchitTG() {
  try {
    S.tg = await api('DELETE', '/telegram/link')
    renderTG()
    render()
    pokazatToast('Telegram отключён')
  } catch (e) {
    pokazatToast(e.message)
  }
}

function tekushayaTema() {
  const a = document.documentElement.dataset.theme
  if (a === 'dark' || a === 'light') return a
  return matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function renderTemaBtn() {
  const tema = tekushayaTema()
  $('#nt-theme-btn').replaceChildren(ikonka(tema === 'dark' ? 'sun' : 'moon'), el('span', { class: 'nt-side__label', text: tema === 'dark' ? 'Светлая тема' : 'Тёмная тема' }))
}

function smenitTemu() {
  const n = tekushayaTema() === 'dark' ? 'light' : 'dark'
  document.documentElement.dataset.theme = n
  try {
    localStorage.setItem('ntodo_theme', n)
  } catch {}
  renderTemaBtn()
}

function konfetti() {
  if (umenshitDvizhenie()) return
  try {
    if (sessionStorage.getItem('ntodo_confetti')) return
    sessionStorage.setItem('ntodo_confetti', '1')
  } catch {}
  const box = el('div', { class: 'nt-confetti', 'aria-hidden': 'true' })
  const cveta = ['#E03E3E', '#CB912F', '#448361', '#2383E2', '#9065B0', '#D9730D']
  for (let i = 0; i < 70; i++) {
    const s = el('i')
    s.style.left = Math.random() * 100 + '%'
    s.style.background = cveta[i % cveta.length]
    s.style.animationDelay = Math.random() * 0.4 + 's'
    s.style.animationDuration = 1.6 + Math.random() * 1.2 + 's'
    s.style.setProperty('--dx', Math.random() * 200 - 100 + 'px')
    s.style.setProperty('--r', Math.random() * 720 + 'deg')
    box.append(s)
  }
  $('#ntodo').append(box)
  setTimeout(() => box.remove(), 3200)
}

function otkrytPoisk() {
  const s = $('#nt-search')
  s.hidden = false
  const i = $('#nt-search-inp')
  i.focus()
  i.select()
  $('#ntodo').classList.remove('nt-app--side-open')
}

function zakrytPoisk() {
  $('#nt-search').hidden = true
  $('#nt-search-inp').value = ''
  S.q = ''
  render()
}

function dvigat(shag) {
  const v = vidimyeVse()
  if (!v.length) return
  let i = v.findIndex((t) => t.cid === S.sel)
  i = i < 0 ? (shag > 0 ? 0 : v.length - 1) : Math.max(0, Math.min(v.length - 1, i + shag))
  S.sel = v[i].cid
  render()
  const r = ROWS.get(S.sel)
  if (r) {
    r.focus({ preventScroll: true })
    r.scrollIntoView({ block: 'nearest' })
  }
}

function vydelitDiapazon(cid) {
  const v = vidimyeVse().map((t) => t.cid)
  const a = v.indexOf(S.sel)
  const b = v.indexOf(cid)
  if (a < 0) S.multi.add(cid)
  else for (let i = Math.min(a, b); i <= Math.max(a, b); i++) S.multi.add(v[i])
  render()
}

function privyazatSpisok() {
  const page = $('#nt-page')

  page.addEventListener('change', (e) => {
    if (e.target.classList.contains('nt-check__inp')) {
      const r = e.target.closest('.nt-row')
      if (r) pereklyuchit(r.dataset.cid)
    }
  })

  page.addEventListener('click', (e) => {
    const r = e.target.closest('.nt-row')
    if (!r) return
    const cid = r.dataset.cid
    if (e.target.closest('.nt-check, .nt-grip')) return
    if (e.target.closest('.nt-row__del')) return udalit(cid)
    if (e.target.closest('.nt-row__more')) return menuZadachi(cid, e.target.closest('.nt-row__more'))
    if (e.target.closest('.nt-date')) return popSrok(cid, e.target.closest('.nt-date'))
    if (e.target.closest('.nt-tag')) return popPrioritet(cid, e.target.closest('.nt-tag'))
    if (e.shiftKey) {
      e.preventDefault()
      return vydelitDiapazon(cid)
    }
    if (e.metaKey || e.ctrlKey) {
      if (S.multi.has(cid)) S.multi.delete(cid)
      else S.multi.add(cid)
      S.sel = cid
      return render()
    }
    if (S.multi.size) S.multi.clear()
    if (e.target.closest('.nt-tw')) return nachatRedakt(cid)
    otkrytPanel(cid)
  })

  page.addEventListener('contextmenu', (e) => {
    const r = e.target.closest('.nt-row')
    if (!r || S.editCid) return
    e.preventDefault()
    S.sel = r.dataset.cid
    render()
    menuZadachi(r.dataset.cid, r, { x: e.clientX, y: e.clientY })
  })

  page.addEventListener('keydown', (e) => {
    if (!e.target.classList?.contains('nt-text') || !S.editCid) return
    if (e.key === 'Enter') {
      e.preventDefault()
      e.target.blur()
    } else if (e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      const cid = S.editCid
      zakonchitRedakt(cid, true)
      ROWS.get(cid)?.focus({ preventScroll: true })
    }
  })

  page.addEventListener('focusout', (e) => {
    if (e.target.classList?.contains('nt-text') && S.editCid) zakonchitRedakt(S.editCid)
  })

  page.addEventListener('paste', (e) => {
    if (!e.target.classList?.contains('nt-text') || !S.editCid) return
    e.preventDefault()
    const text = (e.clipboardData?.getData('text/plain') || '').replace(/\s+/g, ' ')
    document.execCommand('insertText', false, text)
  })

  page.addEventListener('animationend', (e) => {
    if (e.target.classList?.contains('nt-row--in')) e.target.classList.remove('nt-row--in')
  })

  svaipy(page)

  S.sortable = Sortable.create($('#nt-list'), {
    handle: '.nt-grip',
    draggable: '.nt-row',
    animation: umenshitDvizhenie() ? 0 : 150,
    easing: 'cubic-bezier(.2,0,0,1)',
    ghostClass: 'nt-row--ghost',
    chosenClass: 'nt-row--chosen',
    onEnd: (ev) => {
      if (ev.oldIndex === ev.newIndex) return
      const old = new Map(S.tasks.map((t) => [t.cid, t.position]))
      const cids = [...$('#nt-list').children].map((r) => r.dataset.cid).filter(Boolean)
      sohranitPoryadok(cids)
      zapomnit(() => {
        const nazad = S.tasks.filter((t) => !t.done && !t.deleted).sort((a, b) => (old.get(a.cid) ?? a.position) - (old.get(b.cid) ?? b.position))
        sohranitPoryadok(nazad.map((t) => t.cid))
      })
    },
  })

  $('#nt-done-head').addEventListener('click', () => {
    if (S.filter === 'done') return
    S.doneOpen = !S.doneOpen
    render()
  })
  $('#nt-clear').addEventListener('click', ochistitVypolnennye)
}

function svaipy(box) {
  let st = null
  box.addEventListener(
    'touchstart',
    (e) => {
      const r = e.target.closest('.nt-row')
      if (!r || e.target.closest('.nt-grip, .nt-check') || S.editCid || e.touches.length > 1) return
      st = { r, x: e.touches[0].clientX, y: e.touches[0].clientY, dx: 0, on: false }
    },
    { passive: true },
  )
  box.addEventListener(
    'touchmove',
    (e) => {
      if (!st) return
      const dx = e.touches[0].clientX - st.x
      const dy = e.touches[0].clientY - st.y
      if (!st.on) {
        if (Math.abs(dx) > 12 && Math.abs(dx) > Math.abs(dy) * 1.5) st.on = true
        else if (Math.abs(dy) > 12) {
          st = null
          return
        } else return
      }
      st.dx = dx
      st.r._.inner.style.transform = `translateX(${dx}px)`
      st.r.dataset.swipe = dx > 0 ? 'done' : 'del'
      st.r.classList.toggle('is-ready', Math.abs(dx) > 80)
    },
    { passive: true },
  )
  const konec = () => {
    if (!st) return
    const { r, dx, on } = st
    st = null
    r._.inner.style.transform = ''
    delete r.dataset.swipe
    r.classList.remove('is-ready')
    if (!on) return
    if (dx > 80) pereklyuchit(r.dataset.cid)
    else if (dx < -80) udalit(r.dataset.cid)
  }
  box.addEventListener('touchend', konec)
  box.addEventListener('touchcancel', konec)
}

function goryachieKlavishi() {
  document.addEventListener('keydown', (e) => {
    const mod = e.ctrlKey || e.metaKey
    if (mod && e.code === 'KeyK') {
      e.preventDefault()
      otkrytPoisk()
      return
    }
    if (e.key === 'Escape') {
      if (POP) return zakrytPop()
      if (S.modal) return S.modal.close()
      if (S.openCid) return zakrytPanel()
      if (S.multi.size) {
        S.multi.clear()
        return render()
      }
      if (!$('#nt-search').hidden) return zakrytPoisk()
      $('#ntodo').classList.remove('nt-app--side-open')
      return
    }
    const tgt = e.target
    if (tgt.closest?.('input, textarea, select, [contenteditable="true"], [contenteditable="plaintext-only"]')) return
    if (S.modal || POP) return
    if (mod && e.code === 'KeyZ' && !e.shiftKey) {
      e.preventDefault()
      otmenit()
      return
    }
    if (mod || e.altKey) return
    if (e.code === 'KeyN') {
      e.preventDefault()
      otkrytVvod()
      return
    }
    if (e.key === '?') {
      e.preventDefault()
      spravka()
      return
    }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault()
      dvigat(e.key === 'ArrowDown' ? 1 : -1)
      return
    }
    const t = S.sel && naiti(S.sel)
    if (!t || t.deleted) return
    if (tgt.closest?.('button, a')) return
    if (e.key === ' ' || e.code === 'KeyX') {
      e.preventDefault()
      if (S.multi.size) [...S.multi].forEach(pereklyuchit)
      else pereklyuchit(t.cid)
      return
    }
    if (e.key === 'Enter') {
      e.preventDefault()
      nachatRedakt(t.cid)
      return
    }
    if (e.key === 'Delete' || e.key === 'Backspace') {
      e.preventDefault()
      if (S.multi.size) {
        const cids = [...S.multi]
        cids.forEach((c) => udalit(c, { toast: false }))
        S.multi.clear()
        pokazatToast(`Удалено задач: ${cids.length}`, { action: 'Отменить', fn: () => cids.forEach(vosstanovit) })
        render()
      } else udalit(t.cid)
    }
  })

  document.addEventListener(
    'pointerdown',
    (e) => {
      if (POP && !POP.el.contains(e.target) && !POP.anchor.contains(e.target)) zakrytPop(false)
    },
    true,
  )
}

function postroitInterfeis() {
  const app = $('#ntodo')
  $('#nt-search-btn').replaceChildren(ikonka('search'), el('span', { class: 'nt-side__label', text: 'Поиск' }), el('kbd', { text: /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘K' : 'Ctrl K' }))
  $('#nt-help-btn').replaceChildren(ikonka('keyboard'), el('span', { class: 'nt-side__label', text: 'Горячие клавиши' }))
  $('#nt-side-toggle').append(ikonka('chevrons'))
  $('#nt-burger').append(ikonka('menu', 18))
  renderTemaBtn()
  renderPolzovatel()
  renderTG()
  postroitFiltry()
  postroitNovuyu()

  try {
    if (localStorage.getItem('ntodo_side') === 'closed') app.classList.add('nt-app--collapsed')
    S.filter = sessionStorage.getItem('ntodo_filter') || 'all'
  } catch {}
  if (!FILTRY.some(([k]) => k === S.filter)) S.filter = 'all'

  $('#nt-search-btn').addEventListener('click', otkrytPoisk)
  $('#nt-help-btn').addEventListener('click', spravka)
  $('#nt-theme-btn').addEventListener('click', smenitTemu)
  $('#nt-fab').addEventListener('click', otkrytVvod)
  $('#nt-side-toggle').addEventListener('click', () => {
    app.classList.add('nt-app--collapsed')
    try {
      localStorage.setItem('ntodo_side', 'closed')
    } catch {}
  })
  $('#nt-burger').addEventListener('click', () => {
    if (matchMedia('(min-width: 1024px)').matches) {
      app.classList.remove('nt-app--collapsed')
      try {
        localStorage.removeItem('ntodo_side')
      } catch {}
    } else app.classList.toggle('nt-app--side-open')
  })
  $('#nt-side-scrim').addEventListener('click', () => app.classList.remove('nt-app--side-open'))
  $('#nt-panel-scrim').addEventListener('click', zakrytPanel)

  const si = $('#nt-search-inp')
  si.addEventListener('input', () => {
    S.q = si.value
    render()
  })
  si.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      e.preventDefault()
      e.stopPropagation()
      zakrytPoisk()
    } else if (e.key === 'ArrowDown') {
      e.preventDefault()
      si.blur()
      dvigat(1)
    }
  })

  matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', renderTemaBtn)
}

async function importGostevyh() {
  const g = (chitat('ntodo_guest') || []).filter((t) => t && !t.deleted && t.title)
  if (!g.length) return
  try {
    const res = await api('POST', '/tasks/import', { tasks: g.map((t) => ({ ...dlyaServera(t), client_uid: t.cid })) })
    localStorage.removeItem('ntodo_guest')
    for (const st of res.tasks || []) {
      const n = izServera(st)
      if (!S.tasks.some((x) => x.cid === n.cid || x.id === n.id)) S.tasks.push(n)
    }
    sohranitKesh()
    render()
    if (res.tasks?.length) pokazatToast(`Гостевые задачи перенесены в аккаунт: ${res.tasks.length}`)
  } catch {}
}

function proverkaTZ() {
  try {
    const tz = Intl.DateTimeFormat().resolvedOptions().timeZone
    if (tz && tz !== N.tz) api('POST', '/profile', { timezone: tz }).catch(() => {})
  } catch {}
}

function Start() {
  if (!$('#ntodo')) return
  if (S.user) {
    S.queue = chitat(KLUCH_Q) || []
    const kesh = chitat(KLUCH)
    if (S.queue.length && Array.isArray(kesh)) S.tasks = kesh
    else S.tasks = (N.tasks || []).map(izServera)
  } else {
    S.tasks = (chitat(KLUCH) || []).filter((t) => t && !t.deleted && t.cid)
  }

  postroitInterfeis()
  privyazatSpisok()
  goryachieKlavishi()
  render()
  sohranitKesh()

  addEventListener('online', () => {
    S.online = true
    Obrabotat_ochered()
  })
  addEventListener('offline', () => {
    S.online = false
    obnovitStatus()
  })
  setInterval(() => {
    if (S.queue.length && !S.busy) Obrabotat_ochered()
  }, 10000)
  setInterval(render, 60000)

  if (S.user) {
    importGostevyh()
    proverkaTZ()
    Obrabotat_ochered()
    document.addEventListener('visibilitychange', async () => {
      if (document.visibilityState !== 'visible') return
      try {
        S.tg = await api('GET', '/telegram/status')
        renderTG()
      } catch {}
    })
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', Start)
else Start()
