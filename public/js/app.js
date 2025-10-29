const screens = {
  loading: document.getElementById('loadingScreen'),
  setup: document.getElementById('setupScreen'),
  login: document.getElementById('loginScreen'),
  password: document.getElementById('passwordScreen'),
  main: document.getElementById('mainScreen'),
};

const toastEl = document.getElementById('toast');
const setupForm = document.getElementById('setupForm');
const setupPassword = document.getElementById('setupPassword');
const setupPasswordConfirm = document.getElementById('setupPasswordConfirm');
const setupError = document.getElementById('setupError');
const loginForm = document.getElementById('loginForm');
const loginError = document.getElementById('loginError');
const passwordForm = document.getElementById('passwordForm');
const passwordError = document.getElementById('passwordError');
const passwordCurrent = document.getElementById('passwordCurrent');
const passwordNew = document.getElementById('passwordNew');
const passwordConfirm = document.getElementById('passwordConfirm');
const navGroup = document.getElementById('navGroup');
const teamSelect = document.getElementById('teamSelect');
const pageTitle = document.getElementById('pageTitle');
const teamAccessLabel = document.getElementById('teamAccess');
const currentUserEl = document.getElementById('currentUser');
const logoutBtn = document.getElementById('logoutBtn');
const monthInput = document.getElementById('monthInput');
const refreshScheduleBtn = document.getElementById('refreshSchedule');
const exportBtn = document.getElementById('exportCsv');
const scheduleTable = document.getElementById('scheduleTable');
const scheduleStatus = document.getElementById('scheduleStatus');
const shiftList = document.getElementById('shiftList');
const newShiftForm = document.getElementById('newShiftForm');
const accountsMatrix = document.getElementById('accountsMatrix');
const newAccountForm = document.getElementById('newAccountForm');
const teamManagement = document.getElementById('teamManagement');
const newTeamForm = document.getElementById('newTeamForm');
const peopleList = document.getElementById('peopleList');
const newPersonForm = document.getElementById('newPersonForm');

const NAV_CONFIG = [
  { key: 'schedule', label: '排班表格' },
  { key: 'settings', label: '设置' },
  { key: 'role_permissions', label: '角色权限' },
  { key: 'people', label: '人员管理' },
];

const PAGE_TITLES = {
  schedule: '排班表格',
  settings: '班次设置',
  role_permissions: '角色权限',
  people: '人员管理',
};

const WEEK_LABELS = ['一', '二', '三', '四', '五', '六', '日'];

const state = {
  user: null,
  pages: {},
  teams: [],
  selectedTeamId: null,
  selectedMonth: '',
  currentPage: 'schedule',
  scheduleData: null,
  shifts: [],
  shiftMap: new Map(),
  accounts: [],
  allTeams: [],
  peopleAdmin: [],
};

function setActiveScreen(name) {
  Object.values(screens).forEach((el) => el.classList.remove('active'));
  if (screens[name]) {
    screens[name].classList.add('active');
  }
}

let toastTimer = null;
function showToast(message, variant = 'info') {
  if (!toastEl) return;
  toastEl.textContent = message;
  toastEl.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toastEl.classList.remove('show'), variant === 'error' ? 4000 : 2600);
}

async function fetchJSON(url, options = {}) {
  const opts = {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    ...options,
  };
  const res = await fetch(url, opts);
  const contentType = res.headers.get('content-type') || '';
  let body = null;
  if (contentType.includes('application/json')) {
    body = await res.json();
  } else {
    body = await res.text();
  }
  if (!res.ok) {
    const error = new Error((body && body.error) || res.statusText || '请求失败');
    error.response = body;
    error.status = res.status;
    throw error;
  }
  return body;
}

function monthRange(monthValue) {
  if (!monthValue) {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    return {
      start: `${now.getFullYear()}-${month}-01`,
      end: new Date(now.getFullYear(), now.getMonth() + 1, 0).toISOString().slice(0, 10),
    };
  }
  const [year, month] = monthValue.split('-').map((v) => parseInt(v, 10));
  if (Number.isNaN(year) || Number.isNaN(month)) {
    return monthRange('');
  }
  const start = `${year}-${String(month).padStart(2, '0')}-01`;
  const endDate = new Date(year, month, 0);
  const end = endDate.toISOString().slice(0, 10);
  return { start, end };
}

function selectedTeam() {
  return state.teams.find((team) => team.id === state.selectedTeamId) || null;
}

function teamAccessText(access) {
  if (access === 'write') return '可编辑';
  if (access === 'read') return '只读';
  return '未授权';
}

function canViewPage(page) {
  return Boolean(state.pages[page]?.can_view);
}

function canEditPage(page) {
  return Boolean(state.pages[page]?.can_edit);
}

function canEditSchedule() {
  const team = selectedTeam();
  if (!team) return false;
  return canEditPage('schedule') && team.access === 'write';
}

function canEditPeople() {
  const team = selectedTeam();
  if (!team) return false;
  return canEditPage('people') && team.access === 'write';
}

function setTeamAccessLabel() {
  const team = selectedTeam();
  if (!team) {
    teamAccessLabel.textContent = '尚未授权团队';
    return;
  }
  teamAccessLabel.textContent = `${team.name} · ${teamAccessText(team.access)}`;
}

function updateNav() {
  navGroup.innerHTML = '';
  NAV_CONFIG.forEach((item) => {
    if (!canViewPage(item.key)) return;
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'nav-button';
    btn.textContent = item.label;
    if (!canEditPage(item.key) && item.key !== 'schedule') {
      const badge = document.createElement('span');
      badge.className = 'subtle-text';
      badge.textContent = '只读';
      btn.appendChild(badge);
    }
    if (state.currentPage === item.key) {
      btn.classList.add('active');
    }
    btn.addEventListener('click', () => switchPage(item.key));
    navGroup.appendChild(btn);
  });
}

function switchPage(page) {
  if (state.currentPage === page || !canViewPage(page)) return;
  state.currentPage = page;
  document.querySelectorAll('.page').forEach((el) => el.classList.remove('active'));
  const pageEl = document.getElementById(`page-${page}`);
  if (pageEl) pageEl.classList.add('active');
  pageTitle.textContent = PAGE_TITLES[page] || '排班协作平台';
  updateNav();
  if (page === 'schedule') {
    loadSchedule();
  } else if (page === 'settings') {
    loadShifts();
  } else if (page === 'role_permissions') {
    loadAccounts();
  } else if (page === 'people') {
    loadPeopleAdmin();
  }
}

function renderTeamSelect() {
  teamSelect.innerHTML = '';
  if (!state.teams.length) {
    const option = document.createElement('option');
    option.value = '';
    option.textContent = '无可访问团队';
    teamSelect.appendChild(option);
    teamSelect.disabled = true;
    return;
  }
  teamSelect.disabled = false;
  state.teams.forEach((team) => {
    const option = document.createElement('option');
    option.value = String(team.id);
    option.textContent = `${team.name}（${teamAccessText(team.access)}）`;
    if (team.id === state.selectedTeamId) option.selected = true;
    teamSelect.appendChild(option);
  });
}

function renderScheduleTable() {
  scheduleTable.innerHTML = '';
  const schedule = state.scheduleData;
  if (!schedule || !schedule.people.length) {
    const empty = document.createElement('div');
    empty.className = 'empty-state';
    empty.textContent = '未找到在排班显示的人员，请在“人员管理”中添加并勾选显示。';
    const wrapper = scheduleTable.parentElement;
    if (wrapper) {
      wrapper.innerHTML = '';
      wrapper.appendChild(empty);
    }
    return;
  }
  const wrapper = scheduleTable.parentElement;
  if (wrapper && !wrapper.contains(scheduleTable)) {
    wrapper.innerHTML = '';
    wrapper.appendChild(scheduleTable);
  }
  const thead = scheduleTable.createTHead();
  const headRow = thead.insertRow();
  const thDate = document.createElement('th');
  thDate.textContent = '日期';
  thDate.classList.add('sticky-col');
  headRow.appendChild(thDate);
  const thWeek = document.createElement('th');
  thWeek.textContent = '星期';
  thWeek.classList.add('sticky-col-2');
  headRow.appendChild(thWeek);
  schedule.people.forEach((person) => {
    const th = document.createElement('th');
    th.textContent = person.name;
    headRow.appendChild(th);
  });

  const tbody = scheduleTable.createTBody();
  schedule.days.forEach((day) => {
    const tr = tbody.insertRow();
    const dateCell = tr.insertCell();
    dateCell.textContent = day.date;
    dateCell.classList.add('sticky-col');
    const weekCell = tr.insertCell();
    weekCell.textContent = `周${WEEK_LABELS[(day.weekday || 1) - 1] || ''}`;
    weekCell.classList.add('sticky-col-2');

    schedule.people.forEach((person) => {
      const td = tr.insertCell();
      td.classList.add('cell-value');
      td.dataset.personId = String(person.id);
      td.dataset.date = day.date;
      const cellData = day.cells?.[person.id] || null;
      const value = cellData ? cellData.value || '' : '';
      td.dataset.version = String(cellData ? cellData.version : 0);
      td.dataset.value = value;
      if (value) {
        const style = state.shiftMap.get(value);
        td.textContent = value;
        if (style) {
          td.style.background = style.bg_color;
          td.style.color = style.text_color;
        } else {
          td.style.background = '#f8fafc';
          td.style.color = '#0f172a';
        }
      } else {
        td.textContent = '—';
        td.style.background = '#ffffff';
        td.style.color = '#0f172a';
      }
      if (cellData && cellData.updated_by) {
        const updated = new Date((cellData.updated_at || 0) * 1000);
        td.title = `${cellData.updated_by.display_name || ''} · ${updated.toLocaleString('zh-CN', { hour12: false })}`;
      } else {
        td.title = '';
      }
      if (canEditSchedule()) {
        td.tabIndex = 0;
        td.addEventListener('click', () => openShiftMenu(td));
        td.addEventListener('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openShiftMenu(td);
          }
        });
        td.addEventListener('contextmenu', (event) => {
          event.preventDefault();
          applyShift(td, '');
        });
      }
    });
  });
}
let openMenu = null;
function closeMenu() {
  if (openMenu && openMenu.parentElement) {
    openMenu.parentElement.removeChild(openMenu);
  }
  openMenu = null;
  document.removeEventListener('click', handleOutsideClick);
}

function handleOutsideClick(event) {
  if (openMenu && !openMenu.contains(event.target)) {
    closeMenu();
  }
}

function openShiftMenu(cell) {
  closeMenu();
  const rect = cell.getBoundingClientRect();
  const menu = document.createElement('div');
  menu.className = 'dropdown-menu';
  state.shifts
    .slice()
    .sort((a, b) => a.sort_order - b.sort_order)
    .forEach((shift) => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = shift.shift_code;
      btn.style.background = shift.bg_color;
      btn.style.color = shift.text_color;
      btn.addEventListener('click', () => {
        applyShift(cell, shift.shift_code);
        closeMenu();
      });
      menu.appendChild(btn);
    });
  const clearBtn = document.createElement('button');
  clearBtn.type = 'button';
  clearBtn.classList.add('secondary');
  clearBtn.textContent = '清空';
  clearBtn.addEventListener('click', () => {
    applyShift(cell, '');
    closeMenu();
  });
  menu.appendChild(clearBtn);
  document.body.appendChild(menu);
  const top = rect.bottom + window.scrollY + 4;
  const left = rect.left + window.scrollX;
  menu.style.top = `${top}px`;
  menu.style.left = `${left}px`;
  openMenu = menu;
  setTimeout(() => document.addEventListener('click', handleOutsideClick), 0);
}

function randomOpId() {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') {
    return window.crypto.randomUUID();
  }
  return `op-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;
}

async function applyShift(cell, value) {
  if (!canEditSchedule()) {
    showToast('当前团队为只读权限，无法编辑。', 'error');
    return;
  }
  const team = selectedTeam();
  if (!team) {
    showToast('尚未选择团队', 'error');
    return;
  }
  const day = cell.dataset.date;
  const personId = parseInt(cell.dataset.personId || '0', 10);
  const baseVersion = parseInt(cell.dataset.version || '0', 10);
  const opId = randomOpId();
  try {
    const res = await fetchJSON('/api/schedule_update.php', {
      method: 'POST',
      body: JSON.stringify({
        team_id: team.id,
        day,
        person_id: personId,
        value,
        base_version: baseVersion,
        op_id: opId,
      }),
    });
    const newValue = res.value || '';
    cell.dataset.version = String(res.version || 0);
    cell.dataset.value = newValue;
    if (newValue) {
      cell.textContent = newValue;
      const style = state.shiftMap.get(newValue);
      if (style) {
        cell.style.background = style.bg_color;
        cell.style.color = style.text_color;
      } else {
        cell.style.background = '#f8fafc';
        cell.style.color = '#0f172a';
      }
    } else {
      cell.textContent = '—';
      cell.style.background = '#ffffff';
      cell.style.color = '#0f172a';
    }
    if (res.updated_by) {
      const updated = new Date((res.updated_at || Date.now() / 1000) * 1000);
      cell.title = `${res.updated_by.display_name || ''} · ${updated.toLocaleString('zh-CN', { hour12: false })}`;
    } else {
      cell.title = '';
    }
    const dayEntry = state.scheduleData?.days?.find((d) => d.date === day);
    if (dayEntry) {
      if (!dayEntry.cells) dayEntry.cells = {};
      dayEntry.cells[personId] = {
        value: newValue,
        version: res.version || 0,
        updated_at: res.updated_at || Math.floor(Date.now() / 1000),
        updated_by: res.updated_by || null,
      };
    }
  } catch (error) {
    showToast(error.response?.message || error.response?.error || error.message, 'error');
  }
}

async function loadSchedule() {
  const team = selectedTeam();
  if (!team) {
    scheduleTable.innerHTML = '';
    scheduleStatus.textContent = '未授权团队';
    return;
  }
  const { start, end } = monthRange(state.selectedMonth);
  try {
    const res = await fetchJSON(`/api/schedule.php?team_id=${team.id}&start=${start}&end=${end}`);
    state.scheduleData = {
      team: res.team,
      people: res.people || [],
      days: (res.days || []).map((day) => ({
        date: day.date,
        weekday: day.weekday,
        cells: day.cells || {},
      })),
    };
    scheduleStatus.textContent = `${res.people?.length || 0} 人 · ${res.days?.length || 0} 天`;
    renderScheduleTable();
  } catch (error) {
    scheduleStatus.textContent = '加载失败';
    showToast(error.response?.message || error.response?.error || '获取排班失败', 'error');
  }
}

async function loadShifts() {
  if (!canViewPage('settings')) return;
  try {
    const res = await fetchJSON('/api/shifts.php');
    state.shifts = res.shifts || [];
    state.shiftMap = new Map();
    state.shifts.forEach((shift) => {
      state.shiftMap.set(shift.shift_code, shift);
    });
    renderShifts();
  } catch (error) {
    showToast('获取班次失败', 'error');
  }
}

function renderShifts() {
  if (!canViewPage('settings')) {
    shiftList.innerHTML = '<div class="empty-state">暂无访问权限</div>';
    newShiftForm.classList.add('hidden');
    return;
  }
  const canEdit = canEditPage('settings');
  newShiftForm.classList.toggle('hidden', !canEdit);
  if (!state.shifts.length) {
    shiftList.innerHTML = '<div class="empty-state">尚未定义班次，使用下方表单新增。</div>';
    return;
  }
  const table = document.createElement('table');
  table.className = 'data-table';
  const thead = table.createTHead();
  const headRow = thead.insertRow();
  ['班次代码', '显示名称', '背景色', '文字色', '状态', '操作'].forEach((text) => {
    const th = document.createElement('th');
    th.textContent = text;
    headRow.appendChild(th);
  });
  const tbody = table.createTBody();
  state.shifts
    .slice()
    .sort((a, b) => a.sort_order - b.sort_order)
    .forEach((shift, index, arr) => {
      const tr = tbody.insertRow();
      const codeCell = tr.insertCell();
      const codeInput = document.createElement('input');
      codeInput.type = 'text';
      codeInput.value = shift.shift_code;
      codeInput.disabled = !canEdit;
      codeCell.appendChild(codeInput);

      const nameCell = tr.insertCell();
      const nameInput = document.createElement('input');
      nameInput.type = 'text';
      nameInput.value = shift.display_name;
      nameInput.disabled = !canEdit;
      nameCell.appendChild(nameInput);

      const bgCell = tr.insertCell();
      const bgInput = document.createElement('input');
      bgInput.type = 'color';
      bgInput.value = shift.bg_color;
      bgInput.disabled = !canEdit;
      bgCell.appendChild(bgInput);

      const textCell = tr.insertCell();
      const textInput = document.createElement('input');
      textInput.type = 'color';
      textInput.value = shift.text_color;
      textInput.disabled = !canEdit;
      textCell.appendChild(textInput);

      const statusCell = tr.insertCell();
      statusCell.textContent = shift.is_active ? '启用' : '停用';

      const actionCell = tr.insertCell();
      actionCell.className = 'actions-row';
      if (canEdit) {
        const saveBtn = document.createElement('button');
        saveBtn.type = 'button';
        saveBtn.className = 'pill-button';
        saveBtn.textContent = '保存';
        saveBtn.addEventListener('click', async () => {
          try {
            await fetchJSON('/api/shifts.php', {
              method: 'POST',
              body: JSON.stringify({
                action: 'update',
                id: shift.id,
                shift_code: codeInput.value.trim(),
                display_name: nameInput.value.trim(),
                bg_color: bgInput.value,
                text_color: textInput.value,
              }),
            });
            showToast('班次已更新');
            loadShifts();
          } catch (error) {
            showToast(error.response?.error || '更新失败', 'error');
          }
        });
        actionCell.appendChild(saveBtn);

        const toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'pill-button';
        toggleBtn.textContent = shift.is_active ? '停用' : '启用';
        toggleBtn.addEventListener('click', async () => {
          try {
            await fetchJSON('/api/shifts.php', {
              method: 'POST',
              body: JSON.stringify({ action: 'update', id: shift.id, is_active: !shift.is_active }),
            });
            showToast('状态已更新');
            loadShifts();
          } catch (error) {
            showToast('更新失败', 'error');
          }
        });
        actionCell.appendChild(toggleBtn);

        const moveUp = document.createElement('button');
        moveUp.type = 'button';
        moveUp.className = 'pill-button';
        moveUp.textContent = '上移';
        moveUp.disabled = index === 0;
        moveUp.addEventListener('click', () => reorderShift(index, index - 1));
        actionCell.appendChild(moveUp);

        const moveDown = document.createElement('button');
        moveDown.type = 'button';
        moveDown.className = 'pill-button';
        moveDown.textContent = '下移';
        moveDown.disabled = index === arr.length - 1;
        moveDown.addEventListener('click', () => reorderShift(index, index + 1));
        actionCell.appendChild(moveDown);

        const delBtn = document.createElement('button');
        delBtn.type = 'button';
        delBtn.className = 'pill-button';
        delBtn.textContent = '删除';
        delBtn.addEventListener('click', async () => {
          if (!confirm(`确认删除班次 ${shift.shift_code} 吗？`)) return;
          try {
            await fetchJSON('/api/shifts.php', {
              method: 'POST',
              body: JSON.stringify({ action: 'delete', id: shift.id }),
            });
            showToast('班次已删除');
            loadShifts();
          } catch (error) {
            showToast('删除失败', 'error');
          }
        });
        actionCell.appendChild(delBtn);
      }
    });
  shiftList.innerHTML = '';
  shiftList.appendChild(table);
}

async function reorderShift(fromIndex, toIndex) {
  const ordered = state.shifts.slice().sort((a, b) => a.sort_order - b.sort_order);
  const [item] = ordered.splice(fromIndex, 1);
  ordered.splice(toIndex, 0, item);
  try {
    await fetchJSON('/api/shifts.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'sort', order: ordered.map((shift) => shift.id) }),
    });
    loadShifts();
  } catch (error) {
    showToast('排序失败', 'error');
  }
}
function pageAccessToValue(perm) {
  if (!perm || !perm.can_view) return 'hidden';
  return perm.can_edit ? 'write' : 'read';
}

function renderAccounts() {
  if (!canViewPage('role_permissions')) {
    accountsMatrix.innerHTML = '<div class="empty-state">暂无访问权限</div>';
    newAccountForm.classList.add('hidden');
    return;
  }
  const canEdit = canEditPage('role_permissions');
  newAccountForm.classList.toggle('hidden', !canEdit);
  if (!state.accounts.length) {
    accountsMatrix.innerHTML = '<div class="empty-state">尚未创建账号</div>';
    return;
  }
  const table = document.createElement('table');
  table.className = 'data-table';
  const thead = table.createTHead();
  const headRow = thead.insertRow();
  ['账号', '显示名称', '状态', '排班', '设置', '角色权限', '人员'].forEach((text) => {
    const th = document.createElement('th');
    th.textContent = text;
    headRow.appendChild(th);
  });
  state.allTeams.forEach((team) => {
    const th = document.createElement('th');
    th.textContent = `团队：${team.name}`;
    headRow.appendChild(th);
  });
  const thActions = document.createElement('th');
  thActions.textContent = '操作';
  headRow.appendChild(thActions);

  const tbody = table.createTBody();
  state.accounts.forEach((account) => {
    const tr = tbody.insertRow();
    const usernameCell = tr.insertCell();
    usernameCell.textContent = account.username;

    const displayCell = tr.insertCell();
    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.value = account.display_name;
    nameInput.disabled = !canEdit;
    displayCell.appendChild(nameInput);

    const statusCell = tr.insertCell();
    statusCell.textContent = account.is_active ? '启用' : '禁用';

    ['schedule', 'settings', 'role_permissions', 'people'].forEach((page) => {
      const cell = tr.insertCell();
      if (!canEdit && account.id !== state.user.id) {
        const value = pageAccessToValue(account.page_permissions[page]);
        cell.textContent = value === 'write' ? '可编辑' : value === 'read' ? '只读' : '隐藏';
        return;
      }
      const select = document.createElement('select');
      ['hidden', 'read', 'write'].forEach((value) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value === 'hidden' ? '隐藏' : value === 'read' ? '只读' : '可编辑';
        if (pageAccessToValue(account.page_permissions[page]) === value) option.selected = true;
        select.appendChild(option);
      });
      select.disabled = !canEdit;
      select.addEventListener('change', async () => {
        const value = select.value;
        account.page_permissions[page] = {
          can_view: value !== 'hidden',
          can_edit: value === 'write',
        };
        try {
          await saveAccountPermissions(account);
          if (account.id === state.user.id) {
            await refreshSession();
          }
        } catch (error) {
          showToast('保存失败', 'error');
          loadAccounts();
        }
      });
      cell.appendChild(select);
    });

    state.allTeams.forEach((team) => {
      const cell = tr.insertCell();
      const current = account.team_permissions.find((item) => item.team_id === team.id);
      if (!canEdit) {
        cell.textContent = current ? teamAccessText(current.access) : '无权限';
        return;
      }
      const select = document.createElement('select');
      ['none', 'read', 'write'].forEach((value) => {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = value === 'none' ? '无权限' : value === 'read' ? '只读' : '可编辑';
        if ((current?.access || 'none') === value) option.selected = true;
        select.appendChild(option);
      });
      select.addEventListener('change', async () => {
        const value = select.value;
        const existing = account.team_permissions.find((item) => item.team_id === team.id);
        if (value === 'none') {
          account.team_permissions = account.team_permissions.filter((item) => item.team_id !== team.id);
        } else if (existing) {
          existing.access = value;
        } else {
          account.team_permissions.push({ team_id: team.id, access: value, name: team.name, code: team.code });
        }
        try {
          await saveAccountPermissions(account);
          if (account.id === state.user.id) {
            await refreshSession();
          }
        } catch (error) {
          showToast('保存失败', 'error');
          loadAccounts();
        }
      });
      select.disabled = !canEdit;
      cell.appendChild(select);
    });

    const actionsCell = tr.insertCell();
    actionsCell.className = 'actions-row';
    if (canEdit) {
      const saveBtn = document.createElement('button');
      saveBtn.type = 'button';
      saveBtn.className = 'pill-button';
      saveBtn.textContent = '保存信息';
      saveBtn.addEventListener('click', async () => {
        try {
          await fetchJSON('/api/users.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'update', id: account.id, display_name: nameInput.value }),
          });
          showToast('已更新');
          loadAccounts();
          if (account.id === state.user.id) {
            await refreshSession();
          }
        } catch (error) {
          showToast('更新失败', 'error');
        }
      });
      actionsCell.appendChild(saveBtn);

      const toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'pill-button';
      toggleBtn.textContent = account.is_active ? '禁用' : '启用';
      toggleBtn.addEventListener('click', async () => {
        try {
          await fetchJSON('/api/users.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'update', id: account.id, is_active: !account.is_active }),
          });
          showToast('状态已更新');
          loadAccounts();
          if (account.id === state.user.id) {
            await refreshSession();
          }
        } catch (error) {
          showToast('操作失败', 'error');
        }
      });
      actionsCell.appendChild(toggleBtn);

      const resetBtn = document.createElement('button');
      resetBtn.type = 'button';
      resetBtn.className = 'pill-button';
      resetBtn.textContent = '重置密码';
      resetBtn.addEventListener('click', async () => {
        if (!confirm(`将重置账号 ${account.username} 的密码，需重新设置，确定吗？`)) return;
        try {
          await fetchJSON('/api/users.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'reset_password', id: account.id }),
          });
          showToast('已重置密码');
        } catch (error) {
          showToast('重置失败', 'error');
        }
      });
      actionsCell.appendChild(resetBtn);
    }
  });
  accountsMatrix.innerHTML = '';
  accountsMatrix.appendChild(table);
}

async function saveAccountPermissions(account) {
  const pagePayload = {};
  Object.entries(account.page_permissions).forEach(([page, perm]) => {
    pagePayload[page] = pageAccessToValue(perm);
  });
  const teamPayload = {};
  account.team_permissions.forEach((team) => {
    teamPayload[team.team_id] = team.access;
  });
  await fetchJSON('/api/users.php', {
    method: 'POST',
    body: JSON.stringify({
      action: 'set_permissions',
      id: account.id,
      page_permissions: pagePayload,
      team_permissions: teamPayload,
    }),
  });
  showToast('权限已保存');
}
function renderTeamManagement() {
  if (!canViewPage('role_permissions')) {
    teamManagement.innerHTML = '';
    return;
  }
  const canEdit = canEditPage('role_permissions');
  if (!state.allTeams.length) {
    teamManagement.innerHTML = '<div class="empty-state">暂无团队</div>';
    return;
  }
  const table = document.createElement('table');
  table.className = 'data-table';
  const thead = table.createTHead();
  const headRow = thead.insertRow();
  ['名称', '编码', '状态', '操作'].forEach((text) => {
    const th = document.createElement('th');
    th.textContent = text;
    headRow.appendChild(th);
  });
  const tbody = table.createTBody();
  state.allTeams.forEach((team) => {
    const tr = tbody.insertRow();
    const nameCell = tr.insertCell();
    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.value = team.name;
    nameInput.disabled = !canEdit;
    nameCell.appendChild(nameInput);

    const codeCell = tr.insertCell();
    codeCell.textContent = team.code;

    const statusCell = tr.insertCell();
    statusCell.textContent = team.is_active ? '启用' : '停用';

    const actionsCell = tr.insertCell();
    actionsCell.className = 'actions-row';
    if (canEdit) {
      const saveBtn = document.createElement('button');
      saveBtn.type = 'button';
      saveBtn.className = 'pill-button';
      saveBtn.textContent = '保存';
      saveBtn.addEventListener('click', async () => {
        try {
          await fetchJSON('/api/teams.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'update', id: team.id, name: nameInput.value, is_active: team.is_active }),
          });
          showToast('已保存');
          loadTeams(true);
        } catch (error) {
          showToast('保存失败', 'error');
        }
      });
      actionsCell.appendChild(saveBtn);

      const toggleBtn = document.createElement('button');
      toggleBtn.type = 'button';
      toggleBtn.className = 'pill-button';
      toggleBtn.textContent = team.is_active ? '停用' : '启用';
      toggleBtn.addEventListener('click', async () => {
        try {
          await fetchJSON('/api/teams.php', {
            method: 'POST',
            body: JSON.stringify({ action: 'update', id: team.id, name: nameInput.value, is_active: !team.is_active }),
          });
          showToast('状态已更新');
          loadTeams(true);
        } catch (error) {
          showToast('操作失败', 'error');
        }
      });
      actionsCell.appendChild(toggleBtn);
    }
  });
  teamManagement.innerHTML = '';
  teamManagement.appendChild(table);
}

function renderPeople() {
  if (!canViewPage('people')) {
    peopleList.innerHTML = '<div class="empty-state">暂无访问权限</div>';
    newPersonForm.classList.add('hidden');
    return;
  }
  const canEdit = canEditPeople();
  newPersonForm.classList.toggle('hidden', !canEdit);
  if (!state.peopleAdmin.length) {
    peopleList.innerHTML = '<div class="empty-state">该团队暂无人员</div>';
    return;
  }
  const table = document.createElement('table');
  table.className = 'data-table';
  const thead = table.createTHead();
  const headRow = thead.insertRow();
  ['姓名', '在排班显示', '启用', '操作'].forEach((text) => {
    const th = document.createElement('th');
    th.textContent = text;
    headRow.appendChild(th);
  });
  const tbody = table.createTBody();
  state.peopleAdmin.forEach((person, index, arr) => {
    const tr = tbody.insertRow();
    const nameCell = tr.insertCell();
    const nameInput = document.createElement('input');
    nameInput.type = 'text';
    nameInput.value = person.name;
    nameInput.disabled = !canEdit;
    nameCell.appendChild(nameInput);

    const showCell = tr.insertCell();
    const showCheckbox = document.createElement('input');
    showCheckbox.type = 'checkbox';
    showCheckbox.className = 'checkbox';
    showCheckbox.checked = person.show_in_schedule;
    showCheckbox.disabled = !canEdit;
    showCheckbox.addEventListener('change', () => updatePerson(person.id, { show_in_schedule: showCheckbox.checked }));
    showCell.appendChild(showCheckbox);

    const activeCell = tr.insertCell();
    const activeCheckbox = document.createElement('input');
    activeCheckbox.type = 'checkbox';
    activeCheckbox.className = 'checkbox';
    activeCheckbox.checked = person.active;
    activeCheckbox.disabled = !canEdit;
    activeCheckbox.addEventListener('change', () => updatePerson(person.id, { active: activeCheckbox.checked }));
    activeCell.appendChild(activeCheckbox);

    const actionsCell = tr.insertCell();
    actionsCell.className = 'actions-row';
    if (canEdit) {
      const saveBtn = document.createElement('button');
      saveBtn.type = 'button';
      saveBtn.className = 'pill-button';
      saveBtn.textContent = '保存';
      saveBtn.addEventListener('click', () => updatePerson(person.id, { name: nameInput.value }));
      actionsCell.appendChild(saveBtn);

      const upBtn = document.createElement('button');
      upBtn.type = 'button';
      upBtn.className = 'pill-button';
      upBtn.textContent = '上移';
      upBtn.disabled = index === 0;
      upBtn.addEventListener('click', () => movePerson(person.id, 'up'));
      actionsCell.appendChild(upBtn);

      const downBtn = document.createElement('button');
      downBtn.type = 'button';
      downBtn.className = 'pill-button';
      downBtn.textContent = '下移';
      downBtn.disabled = index === arr.length - 1;
      downBtn.addEventListener('click', () => movePerson(person.id, 'down'));
      actionsCell.appendChild(downBtn);

      const delBtn = document.createElement('button');
      delBtn.type = 'button';
      delBtn.className = 'pill-button';
      delBtn.textContent = '删除';
      delBtn.addEventListener('click', () => deletePerson(person.id));
      actionsCell.appendChild(delBtn);
    }
  });
  peopleList.innerHTML = '';
  peopleList.appendChild(table);
}
async function updatePerson(id, payload) {
  const team = selectedTeam();
  if (!team) return;
  try {
    await fetchJSON('/api/people.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'update', team_id: team.id, id, ...payload }),
    });
    showToast('已保存');
    loadPeopleAdmin();
    loadSchedule();
  } catch (error) {
    showToast('保存失败', 'error');
  }
}

async function movePerson(id, direction) {
  const team = selectedTeam();
  if (!team) return;
  try {
    await fetchJSON('/api/people.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'move', team_id: team.id, id, direction }),
    });
    loadPeopleAdmin();
    loadSchedule();
  } catch (error) {
    showToast('调整失败', 'error');
  }
}

async function deletePerson(id) {
  const team = selectedTeam();
  if (!team) return;
  if (!confirm('确认删除该成员吗？')) return;
  try {
    await fetchJSON('/api/people.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'delete', team_id: team.id, id }),
    });
    loadPeopleAdmin();
    loadSchedule();
  } catch (error) {
    showToast('删除失败', 'error');
  }
}

async function loadAccounts() {
  if (!canViewPage('role_permissions')) return;
  try {
    const res = await fetchJSON('/api/users.php');
    state.accounts = res.accounts || [];
    await loadTeams(true);
    renderAccounts();
  } catch (error) {
    showToast('获取账号信息失败', 'error');
  }
}

async function loadTeams(includeAll = false) {
  try {
    const url = includeAll ? '/api/teams.php?scope=all' : '/api/teams.php';
    const res = await fetchJSON(url);
    if (includeAll) {
      state.allTeams = res.teams || [];
      renderTeamManagement();
    } else {
      state.teams = res.teams || [];
      if (!state.selectedTeamId || !state.teams.find((team) => team.id === state.selectedTeamId)) {
        state.selectedTeamId = state.teams[0]?.id || null;
      }
      renderTeamSelect();
      setTeamAccessLabel();
    }
  } catch (error) {
    showToast('获取团队失败', 'error');
  }
}

async function loadPeopleAdmin() {
  if (!canViewPage('people')) return;
  const team = selectedTeam();
  if (!team) {
    peopleList.innerHTML = '<div class="empty-state">未选择团队</div>';
    return;
  }
  try {
    const res = await fetchJSON(`/api/people.php?team_id=${team.id}`);
    state.peopleAdmin = res.people || [];
    renderPeople();
  } catch (error) {
    showToast('获取人员失败', 'error');
  }
}

async function refreshSession() {
  try {
    const res = await fetchJSON('/api/auth_bootstrap.php');
    if (!res.authenticated) {
      state.user = null;
      setActiveScreen('login');
      return;
    }
    state.user = res.user;
    state.pages = res.pages || {};
    state.teams = res.teams || [];
    if (res.default_team_id) {
      state.selectedTeamId = res.default_team_id;
    } else if (state.teams.length) {
      state.selectedTeamId = state.teams[0].id;
    }
    renderTeamSelect();
    setTeamAccessLabel();
    currentUserEl.textContent = state.user.display_name || state.user.username;
    updateNav();
  } catch (error) {
    console.error(error);
  }
}

async function bootstrap() {
  try {
    const res = await fetchJSON('/api/auth_bootstrap.php');
    if (res.setup_required) {
      setActiveScreen('setup');
      return;
    }
    if (!res.authenticated) {
      setActiveScreen('login');
      return;
    }
    state.user = res.user;
    state.pages = res.pages || {};
    state.teams = res.teams || [];
    state.selectedTeamId = res.default_team_id || state.teams[0]?.id || null;
    state.selectedMonth = res.default_month || new Date().toISOString().slice(0, 7);
    if (monthInput) monthInput.value = state.selectedMonth;
    currentUserEl.textContent = state.user.display_name || state.user.username;
    renderTeamSelect();
    setTeamAccessLabel();
    updateNav();
    setActiveScreen('main');
    switchPage('schedule');
    await loadShifts();
    await loadSchedule();
  } catch (error) {
    if (error.status === 401) {
      setActiveScreen('login');
    } else {
      showToast('初始化失败', 'error');
      setActiveScreen('login');
    }
  }
}
setupForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  setupError.hidden = true;
  if (setupPassword.value !== setupPasswordConfirm.value) {
    setupError.textContent = '两次输入的密码不一致';
    setupError.hidden = false;
    return;
  }
  try {
    await fetchJSON('/api/auth_set_password.php', {
      method: 'POST',
      body: JSON.stringify({ username: 'admin', new_password: setupPassword.value, confirm_password: setupPasswordConfirm.value }),
    });
    showToast('密码设置成功');
    await bootstrap();
  } catch (error) {
    setupError.textContent = error.response?.error || '设置失败';
    setupError.hidden = false;
  }
});

loginForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  loginError.hidden = true;
  const username = document.getElementById('loginUsername').value.trim();
  const password = document.getElementById('loginPassword').value;
  try {
    const res = await fetchJSON('/api/auth_login.php', {
      method: 'POST',
      body: JSON.stringify({ username, password }),
    });
    if (res.user?.must_reset_password) {
      state.user = res.user;
      setActiveScreen('password');
      return;
    }
    await bootstrap();
  } catch (error) {
    loginError.textContent = error.response?.error === 'invalid_credentials' ? '用户名或密码错误' : '登录失败';
    loginError.hidden = false;
  }
});

passwordForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  passwordError.hidden = true;
  if (passwordNew.value !== passwordConfirm.value) {
    passwordError.textContent = '两次输入的密码不一致';
    passwordError.hidden = false;
    return;
  }
  try {
    await fetchJSON('/api/auth_set_password.php', {
      method: 'POST',
      body: JSON.stringify({
        current_password: passwordCurrent.value,
        new_password: passwordNew.value,
        confirm_password: passwordConfirm.value,
      }),
    });
    showToast('密码已更新');
    await bootstrap();
  } catch (error) {
    passwordError.textContent = error.response?.error === 'invalid_current_password' ? '当前密码不正确' : '更新失败';
    passwordError.hidden = false;
  }
});

logoutBtn?.addEventListener('click', async () => {
  try {
    await fetchJSON('/api/auth_logout.php', { method: 'POST' });
    state.user = null;
    setActiveScreen('login');
  } catch (error) {
    showToast('登出失败', 'error');
  }
});

teamSelect?.addEventListener('change', () => {
  state.selectedTeamId = parseInt(teamSelect.value, 10);
  setTeamAccessLabel();
  if (state.currentPage === 'people') {
    loadPeopleAdmin();
  }
  loadSchedule();
});

monthInput?.addEventListener('change', () => {
  state.selectedMonth = monthInput.value;
  loadSchedule();
});

refreshScheduleBtn?.addEventListener('click', () => {
  loadSchedule();
});

exportBtn?.addEventListener('click', () => {
  const team = selectedTeam();
  if (!team) {
    showToast('未选择团队', 'error');
    return;
  }
  const { start, end } = monthRange(state.selectedMonth);
  window.open(`/api/schedule_export.php?team_id=${team.id}&start=${start}&end=${end}`, '_blank');
});

newShiftForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const formData = new FormData(newShiftForm);
  const payload = Object.fromEntries(formData.entries());
  try {
    await fetchJSON('/api/shifts.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'create', ...payload }),
    });
    newShiftForm.reset();
    showToast('班次已创建');
    loadShifts();
  } catch (error) {
    showToast(error.response?.error || '创建失败', 'error');
  }
});

newAccountForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const formData = new FormData(newAccountForm);
  const payload = Object.fromEntries(formData.entries());
  try {
    await fetchJSON('/api/users.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'create', ...payload }),
    });
    newAccountForm.reset();
    showToast('账号已创建');
    loadAccounts();
  } catch (error) {
    showToast(error.response?.error || '创建失败', 'error');
  }
});

newTeamForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const formData = new FormData(newTeamForm);
  const payload = Object.fromEntries(formData.entries());
  try {
    await fetchJSON('/api/teams.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'create', ...payload }),
    });
    newTeamForm.reset();
    showToast('团队已创建');
    await loadTeams(true);
    await refreshSession();
  } catch (error) {
    showToast(error.response?.error || '创建失败', 'error');
  }
});

newPersonForm?.addEventListener('submit', async (event) => {
  event.preventDefault();
  const team = selectedTeam();
  if (!team) {
    showToast('未选择团队', 'error');
    return;
  }
  const formData = new FormData(newPersonForm);
  const payload = Object.fromEntries(formData.entries());
  payload.show_in_schedule = formData.get('show_in_schedule') === 'on';
  payload.active = formData.get('active') === 'on';
  try {
    await fetchJSON('/api/people.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'create', team_id: team.id, ...payload }),
    });
    newPersonForm.reset();
    showToast('成员已添加');
    loadPeopleAdmin();
    loadSchedule();
  } catch (error) {
    showToast('添加失败', 'error');
  }
});

bootstrap();
