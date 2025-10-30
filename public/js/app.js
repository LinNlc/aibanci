const API_BASE = '/api';
const state = {
  user: null,
  teams: [],
  currentTeamId: null,
  currentPage: 'schedule',
  currentMonth: new Date(),
  loginStep: 'login',
  dataCache: {
    schedule: null,
    shifts: null,
    people: null,
    permissions: null,
  },
  pinnedSidebar: false,
};

const root = document.getElementById('app');
const toastEl = document.getElementById('toast');
let dropdownInstance = null;

function showToast(message, timeout = 3000) {
  if (!toastEl) return;
  toastEl.textContent = message;
  toastEl.classList.add('show');
  setTimeout(() => toastEl.classList.remove('show'), timeout);
}

async function apiFetch(path, options = {}) {
  const opts = {
    credentials: 'include',
    headers: {'Content-Type': 'application/json', ...(options.headers || {})},
    ...options,
  };
  if (opts.body && typeof opts.body !== 'string') {
    opts.body = JSON.stringify(opts.body);
  }
  const response = await fetch(`${API_BASE}${path}`, opts);
  if (!response.ok) {
    let detail = null;
    try {
      detail = await response.json();
    } catch (e) {
      // ignore
    }
    const error = new Error('Request failed');
    error.status = response.status;
    error.detail = detail;
    throw error;
  }
  if (response.status === 204) return null;
  const contentType = response.headers.get('content-type') || '';
  if (contentType.includes('application/json')) {
    return response.json();
  }
  return response.text();
}

function formatMonth(dateObj) {
  const year = dateObj.getFullYear();
  const month = `${dateObj.getMonth() + 1}`.padStart(2, '0');
  return `${year}-${month}`;
}

function startOfMonth(dateObj) {
  return new Date(dateObj.getFullYear(), dateObj.getMonth(), 1);
}

function endOfMonth(dateObj) {
  return new Date(dateObj.getFullYear(), dateObj.getMonth() + 1, 0);
}

function hasPagePermission(page, type = 'view') {
  if (!state.user) return false;
  const perm = state.user.pages.find((p) => p.page === page);
  if (!perm) return false;
  if (type === 'edit') {
    return perm.can_edit;
  }
  return perm.can_view;
}

function currentTeamPermission() {
  if (!state.user || !state.currentTeamId) return null;
  return state.user.teams.find((t) => t.team_id === state.currentTeamId) || null;
}

async function initialize() {
  try {
    const user = await apiFetch('/auth/me');
    state.user = user;
    await loadTeams();
    renderApp();
    await refreshPageData();
  } catch (error) {
    renderLogin();
  }
}

async function loadTeams() {
  const teams = await apiFetch('/teams');
  state.teams = teams;
  if (!state.currentTeamId && teams.length > 0) {
    state.currentTeamId = teams[0].id;
  }
}

function renderLogin() {
  root.innerHTML = '';
  const wrapper = document.createElement('div');
  wrapper.className = 'login-wrapper active';
  const title = state.loginStep === 'login' ? '登录排班系统' : '首次设置密码';
  wrapper.innerHTML = `
    <h2 style="margin-top:0;margin-bottom:1.5rem;font-weight:600;">${title}</h2>
    <form id="loginForm" class="login-form" style="display:flex;flex-direction:column;gap:1rem;">
      <label style="display:flex;flex-direction:column;gap:0.35rem;">
        <span>用户名</span>
        <input type="text" name="username" required value="${state.lastUsername || ''}" />
      </label>
      ${state.loginStep === 'login' ? `
      <label style="display:flex;flex-direction:column;gap:0.35rem;">
        <span>密码</span>
        <input type="password" name="password" required />
      </label>` : `
      <label style="display:flex;flex-direction:column;gap:0.35rem;">
        <span>当前密码</span>
        <input type="password" name="current_password" required />
      </label>
      <label style="display:flex;flex-direction:column;gap:0.35rem;">
        <span>新密码</span>
        <input type="password" name="new_password" required minlength="8" />
      </label>`}
      <button type="submit">${state.loginStep === 'login' ? '登录' : '提交并登录'}</button>
    </form>
  `;
  root.appendChild(wrapper);

  const form = wrapper.querySelector('#loginForm');
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(form);
    const username = formData.get('username');
    state.lastUsername = username;
    try {
      if (state.loginStep === 'login') {
        const result = await apiFetch('/auth/login', {
          method: 'POST',
          body: { username, password: formData.get('password') },
        });
        if (result.must_change_password) {
          state.loginStep = 'first';
          renderLogin();
          showToast('请设置一个新的管理员密码');
          return;
        }
        state.user = result.user;
      } else {
        const result = await apiFetch('/auth/first-login', {
          method: 'POST',
          body: {
            username,
            current_password: formData.get('current_password'),
            new_password: formData.get('new_password'),
          },
        });
        state.user = result.user;
      }
      state.loginStep = 'login';
      await loadTeams();
      renderApp();
      await refreshPageData();
      showToast('欢迎回来');
    } catch (error) {
      if (error.status === 401) {
        showToast('用户名或密码错误');
      } else {
        showToast('登录失败，请稍后重试');
      }
    }
  });
}

function renderApp() {
  if (!state.user) {
    renderLogin();
    return;
  }
  const sidebarPinned = state.pinnedSidebar ? ' sidebar is-pinned' : ' sidebar';
  root.innerHTML = `
    <div class="app-shell">
      <aside class="${sidebarPinned}" id="sidebar">
        <div class="logo">排班</div>
        <button class="nav-item secondary" id="pinSidebar" type="button" style="gap:0.5rem;align-items:center;justify-content:flex-start;">
          <span class="icon">📌</span>
          <span>${state.pinnedSidebar ? '取消固定' : '固定侧栏'}</span>
        </button>
        <div id="navItems" style="width:100%;display:flex;flex-direction:column;gap:0.35rem;"></div>
      </aside>
      <div class="main-area">
        <header class="topbar">
          <div style="display:flex;flex-direction:column;gap:0.25rem;">
            <h1>团队排班控制台</h1>
            <div style="font-size:0.85rem;color:#64748b;">${state.user.display_name}</div>
          </div>
          <div class="team-select">
            <label style="display:flex;flex-direction:column;font-size:0.85rem;color:#475569;gap:0.35rem;">
              团队
              <select id="teamSelector"></select>
            </label>
            <button class="secondary" id="logoutBtn" type="button">退出登录</button>
          </div>
        </header>
        <main class="content" id="pageContent"></main>
      </div>
    </div>
  `;

  const navContainer = document.getElementById('navItems');
  const visiblePages = state.user.pages.filter((p) => p.can_view);
  const pageConfigs = {
    schedule: { label: '排班表格', icon: '📅' },
    settings: { label: '班次设置', icon: '🎨' },
    permissions: { label: '角色权限', icon: '🛡️' },
    people: { label: '人员管理', icon: '👥' },
  };
  visiblePages.forEach((perm) => {
    const config = pageConfigs[perm.page];
    if (!config) return;
    const item = document.createElement('button');
    item.type = 'button';
    item.className = `nav-item${state.currentPage === perm.page ? ' active' : ''}`;
    item.innerHTML = `<span class="icon">${config.icon}</span><span>${config.label}</span>`;
    item.addEventListener('click', () => {
      state.currentPage = perm.page;
      renderApp();
      refreshPageData();
    });
    navContainer.appendChild(item);
  });

  const selector = document.getElementById('teamSelector');
  state.teams.forEach((team) => {
    const option = document.createElement('option');
    option.value = team.id;
    option.textContent = `${team.name}（${team.access_level === 'write' ? '可编辑' : '只读'}）`;
    if (team.id === state.currentTeamId) option.selected = true;
    selector.appendChild(option);
  });
  selector.addEventListener('change', async (event) => {
    state.currentTeamId = Number(event.target.value);
    state.dataCache = { schedule: null, shifts: null, people: null, permissions: null };
    await refreshPageData();
  });

  document.getElementById('logoutBtn').addEventListener('click', async () => {
    await apiFetch('/auth/logout', { method: 'POST' });
    state.user = null;
    state.dataCache = { schedule: null, shifts: null, people: null, permissions: null };
    state.currentTeamId = null;
    state.teams = [];
    state.loginStep = 'login';
    renderLogin();
  });

  document.getElementById('pinSidebar').addEventListener('click', () => {
    state.pinnedSidebar = !state.pinnedSidebar;
    renderApp();
  });

  renderPageContent();
}

async function refreshPageData() {
  if (!state.currentTeamId) return;
  try {
    if (state.currentPage === 'schedule' || state.currentPage === 'settings') {
      await loadSchedule();
    }
    if (state.currentPage === 'settings') {
      await loadShiftSettings();
    }
    if (state.currentPage === 'people') {
      await loadPeople();
    }
    if (state.currentPage === 'permissions') {
      await loadPermissionOverview();
    }
    renderPageContent();
  } catch (error) {
    if (error.status === 401) {
      state.user = null;
      renderLogin();
    } else {
      showToast('加载数据失败');
    }
  }
}

async function loadSchedule() {
  const start = startOfMonth(state.currentMonth);
  const end = endOfMonth(state.currentMonth);
  const startStr = start.toISOString().slice(0, 10);
  const endStr = end.toISOString().slice(0, 10);
  const data = await apiFetch(`/schedule?team_id=${state.currentTeamId}&start=${startStr}&end=${endStr}`);
  state.dataCache.schedule = data;
}

async function loadShiftSettings() {
  const shifts = await apiFetch(`/teams/${state.currentTeamId}/shifts`);
  state.dataCache.shifts = shifts;
}

async function loadPeople() {
  const people = await apiFetch(`/teams/${state.currentTeamId}/people`);
  state.dataCache.people = people;
}

async function loadPermissionOverview() {
  const overview = await apiFetch('/permissions/overview');
  state.dataCache.permissions = overview;
}

function renderPageContent() {
  const container = document.getElementById('pageContent');
  if (!container) return;
  if (!state.currentTeamId) {
    container.innerHTML = '<div class="empty-state">当前账号暂无可访问的团队</div>';
    return;
  }
  switch (state.currentPage) {
    case 'schedule':
      renderSchedulePage(container);
      break;
    case 'settings':
      renderSettingsPage(container);
      break;
    case 'people':
      renderPeoplePage(container);
      break;
    case 'permissions':
      renderPermissionsPage(container);
      break;
    default:
      container.innerHTML = '';
  }
}

function renderSchedulePage(container) {
  const data = state.dataCache.schedule;
  if (!data) {
    container.innerHTML = '<div class="card">正在加载排班数据...</div>';
    return;
  }
  const readOnly = data.read_only;
  const monthValue = formatMonth(state.currentMonth);
  const teamPerm = currentTeamPermission();
  container.innerHTML = `
    <div class="card" style="display:flex;flex-direction:column;gap:1rem;">
      <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:center;justify-content:space-between;">
        <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
          <label style="display:flex;flex-direction:column;gap:0.35rem;font-size:0.85rem;color:#475569;">
            选择月份
            <input type="month" id="monthPicker" value="${monthValue}" />
          </label>
          <div class="tag">${teamPerm ? (teamPerm.access_level === 'write' ? '当前团队：可编辑' : '当前团队：只读') : ''}</div>
        </div>
        <button id="exportCsv" class="secondary" type="button">导出 CSV</button>
      </div>
      <div class="schedule-grid">
        ${renderScheduleTable(data, readOnly)}
      </div>
    </div>
  `;

  container.querySelector('#monthPicker').addEventListener('change', async (event) => {
    const [year, month] = event.target.value.split('-').map((part) => Number(part));
    state.currentMonth = new Date(year, month - 1, 1);
    await loadSchedule();
    renderSchedulePage(container);
  });

  container.querySelector('#exportCsv').addEventListener('click', handleExportCsv);
}

function renderScheduleTable(data, readOnly) {
  const people = data.people;
  const days = data.days;
  const shiftLookup = new Map((data.shifts || []).map((shift) => [shift.code, shift]));
  const editable = !readOnly && hasPagePermission('schedule', 'edit') && currentTeamPermission()?.access_level === 'write';

  let headerCells = '<th>日期</th><th>星期</th>';
  people.forEach((person) => {
    headerCells += `<th>${person.name}</th>`;
  });

  let bodyRows = '';
  days.forEach((day) => {
    let row = `<tr><td>${day.date}</td><td>${day.weekday}</td>`;
    day.assignments.forEach((assignment) => {
      const shift = assignment.shift_code ? shiftLookup.get(assignment.shift_code) : null;
      const display = shift ? shift.display_name : '';
      const styles = shift ? `style="background:${shift.bg_color};color:${shift.text_color};"` : '';
      row += `<td><div class="schedule-cell${editable ? '' : ' readonly'}" data-person="${assignment.person_id}" data-day="${day.date}" ${styles}>${display || ''}</div></td>`;
    });
    row += '</tr>';
    bodyRows += row;
  });

  setTimeout(() => {
    const cells = document.querySelectorAll('.schedule-cell');
    cells.forEach((cell) => {
      if (!editable) return;
      cell.addEventListener('click', (event) => {
        const rect = event.currentTarget.getBoundingClientRect();
        openShiftDropdown({
          anchorRect: rect,
          personId: Number(event.currentTarget.dataset.person),
          day: event.currentTarget.dataset.day,
          current: event.currentTarget.textContent.trim(),
          shiftLookup,
        });
      });
    });
  }, 0);

  return `<table><thead><tr>${headerCells}</tr></thead><tbody>${bodyRows}</tbody></table>`;
}

async function handleExportCsv() {
  if (!state.currentTeamId) return;
  const start = startOfMonth(state.currentMonth).toISOString().slice(0, 10);
  const end = endOfMonth(state.currentMonth).toISOString().slice(0, 10);
  try {
    const response = await fetch(`${API_BASE}/schedule/export?team_id=${state.currentTeamId}&start=${start}&end=${end}`, {
      credentials: 'include',
    });
    if (!response.ok) throw new Error('导出失败');
    const blob = await response.blob();
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `schedule_${start}_${end}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
  } catch (error) {
    showToast('导出失败');
  }
}

function closeDropdown() {
  if (dropdownInstance) {
    dropdownInstance.remove();
    dropdownInstance = null;
  }
}

function openShiftDropdown({ anchorRect, personId, day, current, shiftLookup }) {
  closeDropdown();
  const panel = document.createElement('div');
  panel.className = 'dropdown-panel';
  const shifts = Array.from(shiftLookup.values());
  panel.innerHTML = `
    <button type="button" data-code=""${current === '' ? ' style="font-weight:600;"' : ''}>清空</button>
    ${shifts
      .map(
        (shift) => `
          <button type="button" data-code="${shift.code}" style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
            <span>${shift.display_name}</span>
            <span class="pill" style="background:${shift.bg_color};color:${shift.text_color};">${shift.code}</span>
          </button>`
      )
      .join('')}
  `;
  document.body.appendChild(panel);
  panel.style.top = `${anchorRect.bottom + window.scrollY + 6}px`;
  panel.style.left = `${anchorRect.left + window.scrollX}px`;
  dropdownInstance = panel;
  const handler = async (event) => {
    if (!(event.target instanceof HTMLButtonElement)) return;
    const code = event.target.dataset.code || null;
    try {
      const payload = { team_id: state.currentTeamId, person_id: personId, day, shift_code: code };
      const result = await apiFetch('/schedule/cell', { method: 'PUT', body: payload });
      await loadSchedule();
      renderSchedulePage(document.getElementById('pageContent'));
      showToast('已更新排班');
    } catch (error) {
      showToast('更新失败');
    } finally {
      closeDropdown();
    }
  };
  panel.addEventListener('click', handler, { once: true });
  document.addEventListener(
    'click',
    (evt) => {
      if (dropdownInstance && !dropdownInstance.contains(evt.target)) {
        closeDropdown();
      }
    },
    { once: true }
  );
}

function renderSettingsPage(container) {
  const shifts = state.dataCache.shifts;
  const editable = hasPagePermission('settings', 'edit') && currentTeamPermission()?.access_level === 'write';
  if (!shifts) {
    container.innerHTML = '<div class="card">正在加载班次配置...</div>';
    return;
  }
  const rows = shifts
    .map(
      (shift) => `
      <tr data-id="${shift.id}">
        <td>${shift.code}</td>
        <td><input type="text" data-field="display_name" value="${shift.display_name}" ${editable ? '' : 'disabled'} /></td>
        <td><input type="color" data-field="bg_color" value="${shift.bg_color}" ${editable ? '' : 'disabled'} /></td>
        <td><input type="color" data-field="text_color" value="${shift.text_color}" ${editable ? '' : 'disabled'} /></td>
        <td><input type="number" data-field="sort_order" value="${shift.sort_order}" ${editable ? '' : 'disabled'} /></td>
        <td><input type="checkbox" data-field="is_active" ${shift.is_active ? 'checked' : ''} ${editable ? '' : 'disabled'} /></td>
        ${editable ? '<td><button type="button" class="secondary" data-action="delete">删除</button></td>' : ''}
      </tr>`
    )
    .join('');
  container.innerHTML = `
    <div class="card" style="display:flex;flex-direction:column;gap:1.5rem;">
      <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">
        <div>
          <h2 style="margin:0;font-size:1.1rem;">班次字典</h2>
          <p style="margin:0.25rem 0;color:#64748b;font-size:0.9rem;">修改后立即影响排班表的颜色和名称显示。</p>
        </div>
        ${editable ? '<button type="button" id="addShift">新增班次</button>' : ''}
      </div>
      <div class="schedule-grid">
        <table>
          <thead>
            <tr>
              <th>代码</th>
              <th>显示名称</th>
              <th>背景色</th>
              <th>文字色</th>
              <th>排序</th>
              <th>启用</th>
              ${editable ? '<th>操作</th>' : ''}
            </tr>
          </thead>
          <tbody>${rows || '<tr><td colspan="7" class="empty-state">暂无班次</td></tr>'}</tbody>
        </table>
      </div>
      ${editable ? `
      <form id="shiftForm" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-end;">
        <label style="display:flex;flex-direction:column;gap:0.25rem;">
          代码
          <input required name="code" />
        </label>
        <label style="display:flex;flex-direction:column;gap:0.25rem;">
          显示名
          <input required name="display_name" />
        </label>
        <label style="display:flex;flex-direction:column;gap:0.25rem;">
          背景色
          <input type="color" name="bg_color" value="#f97316" />
        </label>
        <label style="display:flex;flex-direction:column;gap:0.25rem;">
          文字色
          <input type="color" name="text_color" value="#ffffff" />
        </label>
        <label style="display:flex;flex-direction:column;gap:0.25rem;">
          排序
          <input type="number" name="sort_order" value="10" />
        </label>
        <label style="display:flex;align-items:center;gap:0.35rem;">
          <input type="checkbox" name="is_active" checked /> 启用
        </label>
        <button type="submit">新增</button>
      </form>` : ''}
    </div>
  `;

  if (editable) {
    container.querySelectorAll('tbody tr').forEach((row) => {
      row.querySelectorAll('input').forEach((input) => {
        input.addEventListener('change', async (event) => {
          const id = row.dataset.id;
          const field = event.target.dataset.field;
          let value = event.target.type === 'checkbox' ? event.target.checked : event.target.value;
          if (field === 'sort_order') value = Number(value);
          try {
            await apiFetch(`/teams/${state.currentTeamId}/shifts/${id}`, {
              method: 'PUT',
              body: { [field]: value },
            });
            await loadShiftSettings();
            renderSettingsPage(container);
            showToast('班次已更新');
          } catch (error) {
            showToast('更新失败');
          }
        });
      });
      const deleteBtn = row.querySelector('[data-action="delete"]');
      if (deleteBtn) {
        deleteBtn.addEventListener('click', async () => {
          const id = row.dataset.id;
          if (!confirm('确认删除该班次？')) return;
          try {
            await apiFetch(`/teams/${state.currentTeamId}/shifts/${id}`, { method: 'DELETE' });
            await loadShiftSettings();
            renderSettingsPage(container);
            showToast('班次已删除');
          } catch (error) {
            showToast('删除失败');
          }
        });
      }
    });

    const form = container.querySelector('#shiftForm');
    if (form) {
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(form);
        try {
          await apiFetch(`/teams/${state.currentTeamId}/shifts`, {
            method: 'POST',
            body: {
              code: formData.get('code'),
              display_name: formData.get('display_name'),
              bg_color: formData.get('bg_color'),
              text_color: formData.get('text_color'),
              sort_order: Number(formData.get('sort_order') || 0),
              is_active: formData.get('is_active') === 'on',
            },
          });
          form.reset();
          await loadShiftSettings();
          renderSettingsPage(container);
          showToast('班次已新增');
        } catch (error) {
          showToast('新增失败');
        }
      });
    }
  }
}

function renderPeoplePage(container) {
  const people = state.dataCache.people;
  const editable = hasPagePermission('people', 'edit') && currentTeamPermission()?.access_level === 'write';
  if (!people) {
    container.innerHTML = '<div class="card">正在加载人员列表...</div>';
    return;
  }
  const rows = people
    .map(
      (person) => `
      <tr data-id="${person.id}">
        <td>${person.name}</td>
        <td><input type="checkbox" data-field="active" ${person.active ? 'checked' : ''} ${editable ? '' : 'disabled'} /></td>
        <td><input type="checkbox" data-field="show_in_schedule" ${person.show_in_schedule ? 'checked' : ''} ${editable ? '' : 'disabled'} /></td>
        <td><input type="number" data-field="sort_index" value="${person.sort_index}" ${editable ? '' : 'disabled'} /></td>
        ${editable ? '<td><button type="button" class="secondary" data-action="delete">移除</button></td>' : ''}
      </tr>`
    )
    .join('');
  container.innerHTML = `
    <div class="card" style="display:flex;flex-direction:column;gap:1.5rem;">
      <div style="display:flex;align-items:flex-end;gap:1rem;flex-wrap:wrap;">
        <div>
          <h2 style="margin:0;font-size:1.1rem;">团队成员</h2>
          <p style="margin:0.25rem 0;color:#64748b;font-size:0.9rem;">勾选“展示在排班表”后会按排序显示为列。</p>
        </div>
        ${editable ? '<form id="personForm" style="display:flex;gap:0.75rem;align-items:flex-end;flex-wrap:wrap;"><label style="display:flex;flex-direction:column;gap:0.25rem;">姓名<input required name="name" /></label><label style="display:flex;flex-direction:column;gap:0.25rem;">排序<input type="number" name="sort_index" value="10" /></label><label style="display:flex;align-items:center;gap:0.35rem;"><input type="checkbox" name="show_in_schedule" checked /> 排班展示</label><button type="submit">新增成员</button></form>' : ''}
      </div>
      <div class="schedule-grid">
        <table>
          <thead>
            <tr>
              <th>姓名</th>
              <th>启用</th>
              <th>排班展示</th>
              <th>排序值</th>
              ${editable ? '<th>操作</th>' : ''}
            </tr>
          </thead>
          <tbody>${rows || '<tr><td colspan="5" class="empty-state">暂无人员</td></tr>'}</tbody>
        </table>
      </div>
    </div>
  `;

  if (editable) {
    container.querySelectorAll('tbody tr').forEach((row) => {
      row.querySelectorAll('input').forEach((input) => {
        input.addEventListener('change', async (event) => {
          const id = row.dataset.id;
          const field = event.target.dataset.field;
          let value = event.target.type === 'checkbox' ? event.target.checked : Number(event.target.value);
          try {
            await apiFetch(`/teams/${state.currentTeamId}/people/${id}`, {
              method: 'PUT',
              body: { [field]: value },
            });
            await loadPeople();
            renderPeoplePage(container);
            showToast('人员已更新');
          } catch (error) {
            showToast('更新失败');
          }
        });
      });
      const deleteBtn = row.querySelector('[data-action="delete"]');
      if (deleteBtn) {
        deleteBtn.addEventListener('click', async () => {
          if (!confirm('确认移除该成员？')) return;
          const id = row.dataset.id;
          try {
            await apiFetch(`/teams/${state.currentTeamId}/people/${id}`, { method: 'DELETE' });
            await loadPeople();
            renderPeoplePage(container);
            showToast('已移除成员');
          } catch (error) {
            showToast('删除失败');
          }
        });
      }
    });

    const form = container.querySelector('#personForm');
    if (form) {
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const formData = new FormData(form);
        try {
          await apiFetch(`/teams/${state.currentTeamId}/people`, {
            method: 'POST',
            body: {
              name: formData.get('name'),
              sort_index: Number(formData.get('sort_index') || 0),
              show_in_schedule: formData.get('show_in_schedule') === 'on',
            },
          });
          form.reset();
          await loadPeople();
          renderPeoplePage(container);
          showToast('新增成功');
        } catch (error) {
          showToast('新增失败');
        }
      });
    }
  }
}

function renderPermissionsPage(container) {
  const overview = state.dataCache.permissions;
  if (!overview) {
    container.innerHTML = '<div class="card">正在加载权限矩阵...</div>';
    return;
  }
  const editable = hasPagePermission('permissions', 'edit');
  const pageHeaders = ['schedule', 'settings', 'people', 'permissions'];
  const headerRow = pageHeaders
    .map((page) => `<th>${page === 'schedule' ? '排班' : page === 'settings' ? '设置' : page === 'people' ? '人员' : '权限'}</th>`)
    .join('');
  const teamHeaders = overview.teams.map((team) => `<th>${team.name}</th>`).join('');

  const rows = overview.users
    .map((user) => {
      const pageCells = pageHeaders
        .map((page) => {
          const perm = user.pages.find((p) => p.page === page);
          const viewChecked = perm?.can_view ? 'checked' : '';
          const editChecked = perm?.can_edit ? 'checked' : '';
          return `<td data-page="${page}">
            <label style="display:flex;align-items:center;gap:0.3rem;font-size:0.85rem;">
              <input type="checkbox" data-kind="view" ${viewChecked} ${editable ? '' : 'disabled'} /> 可见
            </label>
            <label style="display:flex;align-items:center;gap:0.3rem;font-size:0.85rem;">
              <input type="checkbox" data-kind="edit" ${editChecked} ${editable ? '' : 'disabled'} /> 可编辑
            </label>
          </td>`;
        })
        .join('');
      const teamCells = overview.teams
        .map((team) => {
          const perm = user.teams.find((t) => t.team_id === team.id);
          const value = perm ? perm.access_level : 'none';
          return `<td>
            <select data-team="${team.id}" ${editable ? '' : 'disabled'}>
              <option value="none" ${value === 'none' ? 'selected' : ''}>无权限</option>
              <option value="read" ${value === 'read' ? 'selected' : ''}>只读</option>
              <option value="write" ${value === 'write' ? 'selected' : ''}>可编辑</option>
            </select>
          </td>`;
        })
        .join('');
      return `<tr data-user="${user.id}">
        <td>${user.display_name}<div style="color:#94a3b8;font-size:0.8rem;">${user.username}</div></td>
        ${pageCells}
        ${teamCells}
        <td>${editable ? '<button type="button" class="secondary" data-action="save">保存</button>' : ''}</td>
      </tr>`;
    })
    .join('');

  container.innerHTML = `
    <div class="card" style="display:flex;flex-direction:column;gap:1.5rem;">
      <div style="display:flex;align-items:center;justify-content:space-between;">
        <h2 style="margin:0;font-size:1.1rem;">账号权限矩阵</h2>
        ${editable ? '<button type="button" id="addUser" class="secondary">新增账号</button>' : ''}
      </div>
      <div class="schedule-grid">
        <table>
          <thead>
            <tr>
              <th>账号</th>
              ${headerRow}
              ${teamHeaders}
              <th>操作</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    </div>
  `;

  if (editable) {
    container.querySelectorAll('tbody tr').forEach((row) => {
      const userId = row.dataset.user;
      const saveBtn = row.querySelector('[data-action="save"]');
      saveBtn.addEventListener('click', async () => {
        const pages = pageHeaders.map((page) => {
          const cell = row.querySelector(`td[data-page="${page}"]`);
          const view = cell.querySelector('[data-kind="view"]').checked;
          const edit = cell.querySelector('[data-kind="edit"]').checked;
          return { page, can_view: view, can_edit: edit };
        });
        const teams = overview.teams.map((team) => {
          const select = row.querySelector(`select[data-team="${team.id}"]`);
          const value = select.value;
          return { team_id: team.id, access_level: value === 'none' ? null : value };
        });
        try {
          await apiFetch(`/permissions/users/${userId}`, {
            method: 'PUT',
            body: { pages, teams },
          });
          await loadPermissionOverview();
          renderPermissionsPage(container);
          showToast('权限已保存');
        } catch (error) {
          showToast('保存失败');
        }
      });
    });

    const addBtn = container.querySelector('#addUser');
    if (addBtn) {
      addBtn.addEventListener('click', () => openCreateUserDialog());
    }
  }
}

function openCreateUserDialog() {
  const dialog = document.createElement('div');
  dialog.className = 'dropdown-panel';
  dialog.style.position = 'fixed';
  dialog.style.top = '20%';
  dialog.style.left = '50%';
  dialog.style.transform = 'translateX(-50%)';
  dialog.innerHTML = `
    <form id="createUserForm" style="display:flex;flex-direction:column;gap:0.75rem;width:320px;">
      <h3 style="margin:0;">新增账号</h3>
      <label style="display:flex;flex-direction:column;gap:0.25rem;">
        用户名
        <input name="username" required />
      </label>
      <label style="display:flex;flex-direction:column;gap:0.25rem;">
        显示名
        <input name="display_name" required />
      </label>
      <label style="display:flex;flex-direction:column;gap:0.25rem;">
        初始密码
        <input name="password" type="password" required minlength="8" />
      </label>
      <label style="display:flex;align-items:center;gap:0.35rem;">
        <input type="checkbox" name="must_change_password" checked /> 首次登录需改密
      </label>
      <div style="display:flex;gap:0.5rem;justify-content:flex-end;">
        <button type="button" class="secondary" id="cancelUser">取消</button>
        <button type="submit">创建</button>
      </div>
    </form>
  `;
  document.body.appendChild(dialog);
  const remove = () => dialog.remove();
  dialog.querySelector('#cancelUser').addEventListener('click', remove);
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) remove();
  });
  dialog.querySelector('#createUserForm').addEventListener('submit', async (event) => {
    event.preventDefault();
    const formData = new FormData(event.target);
    try {
      await apiFetch('/permissions/users', {
        method: 'POST',
        body: {
          username: formData.get('username'),
          display_name: formData.get('display_name'),
          password: formData.get('password'),
          must_change_password: formData.get('must_change_password') === 'on',
        },
      });
      remove();
      await loadPermissionOverview();
      renderPermissionsPage(document.getElementById('pageContent'));
      showToast('账号已创建');
    } catch (error) {
      showToast('创建失败');
    }
  });
}

initialize();
