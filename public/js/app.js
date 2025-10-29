const teamInput = document.getElementById('teamInput');
const dayInput = document.getElementById('dayInput');
const reloadBtn = document.getElementById('reloadBtn');
const scheduleBody = document.getElementById('scheduleBody');
const statusBar = document.getElementById('statusBar');
const toastEl = document.getElementById('toast');
const loadingBar = document.getElementById('loadingBar');

const SHIFT_TYPES = Array.isArray(window.SHIFT_TYPES) ? window.SHIFT_TYPES : ['白', '中1', '中2', '夜', '休'];
const API_BASE = '/api';

if (!document.cookie.split('; ').some((c) => c.startsWith('uid='))) {
  document.cookie = 'uid=guest; path=/; max-age=31536000';
}

let currentTeam = '';
let currentDay = '';
let cells = new Map();
let employees = [];
let eventSource = null;
let lastTs = 0;
let reconnectTimer = null;
const lockTimers = new Map();
let openDropdown = null;
let dropdownButton = null;
let syncing = false;

function todayYmd() {
  const dt = new Date();
  const off = dt.getTimezoneOffset();
  const local = new Date(dt.getTime() - off * 60000);
  return local.toISOString().slice(0, 10);
}

dayInput.value = todayYmd();
teamInput.value = '默认团队';

function showToast(message, duration = 2600) {
  toastEl.textContent = message;
  toastEl.classList.add('show');
  setTimeout(() => toastEl.classList.remove('show'), duration);
}

function setLoading(percent) {
  loadingBar.style.setProperty('--progress', `${percent}%`);
}

function formatTime(ts) {
  if (!ts) return '—';
  const d = new Date(ts * 1000);
  return d.toLocaleString('zh-CN', { hour12: false });
}

function renderStatus(text) {
  statusBar.textContent = text;
}

function rowId(emp) {
  return `row-${encodeURIComponent(emp)}`;
}

function renderTable() {
  scheduleBody.innerHTML = '';
  employees.forEach((emp) => {
    const cell = cells.get(emp) || { value: '休', version: 0, updated_at: 0, updated_by: '' };
    const tr = document.createElement('tr');
    tr.id = rowId(emp);

    const nameTd = document.createElement('td');
    nameTd.textContent = emp;
    nameTd.scope = 'row';
    tr.appendChild(nameTd);

    const valueTd = document.createElement('td');
    const wrapper = document.createElement('div');
    wrapper.className = 'cell';

    const input = document.createElement('input');
    input.className = 'cell-value';
    input.value = cell.value || '';
    input.readOnly = true;
    input.dataset.emp = emp;
    input.setAttribute('data-version', cell.version);
    input.addEventListener('focus', () => handleFocus(emp));
    input.addEventListener('blur', () => handleBlur(emp));

    const actions = document.createElement('div');
    actions.className = 'cell-actions';

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'dropdown-trigger';
    trigger.dataset.emp = emp;
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      handleFocus(emp);
      toggleDropdown(trigger, emp);
    });
    trigger.addEventListener('blur', () => handleBlur(emp));

    actions.appendChild(trigger);
    wrapper.appendChild(input);
    wrapper.appendChild(actions);
    valueTd.appendChild(wrapper);
    tr.appendChild(valueTd);

    const metaTd = document.createElement('td');
    metaTd.textContent = cell.updated_by ? `${cell.updated_by} · ${formatTime(cell.updated_at)}` : '—';
    tr.appendChild(metaTd);

    scheduleBody.appendChild(tr);
  });
}

function handleFocus(emp) {
  acquireLock(emp);
}

function handleBlur(emp) {
  releaseLockLater(emp);
}

function acquireLock(emp) {
  clearTimeout(lockTimers.get(emp));
  fetch(`${API_BASE}/lock.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ action: 'acquire', team: currentTeam, day: currentDay, emp }),
  })
    .then((res) => res.json())
    .then((res) => {
      if (res && res.locked) {
        if (res.risk) {
          showToast(`注意：${emp} 当前可能由 ${res.locked_by} 编辑`);
        }
        scheduleRenew(emp);
      }
    })
    .catch((err) => console.error(err));
}

function scheduleRenew(emp) {
  clearTimeout(lockTimers.get(emp));
  const timer = setTimeout(() => renewLock(emp), 12000);
  lockTimers.set(emp, timer);
}

function renewLock(emp) {
  fetch(`${API_BASE}/lock.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({ action: 'renew', team: currentTeam, day: currentDay, emp }),
  })
    .then((res) => res.json())
    .then((res) => {
      if (res.locked) {
        scheduleRenew(emp);
      } else if (res.risk) {
        showToast(`锁续期失败：${emp} 已被 ${res.locked_by} 使用`);
      }
    })
    .catch(() => {});
}

function releaseLockLater(emp) {
  const timer = setTimeout(() => {
    fetch(`${API_BASE}/lock.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ action: 'release', team: currentTeam, day: currentDay, emp }),
    }).catch(() => {});
  }, 2000);
  lockTimers.set(emp, timer);
}

function updateCell(emp, value) {
  const cell = cells.get(emp) || { version: 0 };
  const opId = crypto.randomUUID ? crypto.randomUUID() : `op_${Date.now()}_${Math.random().toString(36).slice(2)}`;
  setLoading(45);
  fetch(`${API_BASE}/update_cell.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'include',
    body: JSON.stringify({
      team: currentTeam,
      day: currentDay,
      emp,
      new_value: value,
      base_version: cell.version ?? 0,
      op_id: opId,
    }),
  })
    .then(async (res) => {
      const data = await res.json();
      if (!res.ok || !data.applied) {
        if (data.reason === 'conflict') {
          showToast('存在冲突，已回滚并刷新最新数据');
          return refreshCell(emp);
        }
        throw new Error(data.error || '更新失败');
      }
      cells.set(emp, {
        value: data.value,
        version: data.version,
        updated_at: data.updated_at,
        updated_by: data.updated_by,
      });
      renderTable();
      showToast(`${emp} 的班次已更新为 ${value}`);
    })
    .catch((err) => {
      console.error(err);
      showToast(`更新失败：${err.message}`);
    })
    .finally(() => setLoading(0));
}

function refreshCell(emp) {
  fetch(`${API_BASE}/get_cell.php?team=${encodeURIComponent(currentTeam)}&day=${encodeURIComponent(currentDay)}&emp=${encodeURIComponent(emp)}`, {
    credentials: 'include',
  })
    .then((res) => res.json())
    .then((data) => {
      cells.set(emp, data);
      renderTable();
    })
    .catch(() => {});
}

function loadSchedule() {
  currentTeam = teamInput.value.trim() || '默认团队';
  currentDay = dayInput.value || todayYmd();
  setLoading(30);
  fetch(`${API_BASE}/get_schedule.php?team=${encodeURIComponent(currentTeam)}&day=${encodeURIComponent(currentDay)}`, {
    credentials: 'include',
  })
    .then((res) => res.json())
    .then((data) => {
      if (data.error) {
        throw new Error(data.error);
      }
      cells = new Map();
      employees = [];
      data.cells.forEach((item) => {
        cells.set(item.emp, item);
        if (!employees.includes(item.emp)) {
          employees.push(item.emp);
        }
      });
      if (employees.length === 0) {
        employees = ['张三', '李四', '王五'];
      }
      renderTable();
      renderStatus(`团队：${currentTeam} · 日期：${currentDay}`);
      lastTs = 0;
      catchupOps().finally(() => {
        connectSSE();
      });
    })
    .catch((err) => {
      console.error(err);
      showToast(`加载失败：${err.message}`);
    })
    .finally(() => setLoading(0));
}

function connectSSE() {
  if (eventSource) {
    eventSource.close();
  }
  const url = `${API_BASE}/sse.php?team=${encodeURIComponent(currentTeam)}&day=${encodeURIComponent(currentDay)}&since_ts=${lastTs}`;
  eventSource = new EventSource(url, { withCredentials: true });
  eventSource.onmessage = (event) => {
    try {
      const data = JSON.parse(event.data);
      if (data.type === 'cell.update') {
        lastTs = Math.max(lastTs, data.ts);
        applyOp({
          emp: data.emp,
          new_value: data.value,
          base_version: data.base_version,
          user_id: data.by,
          ts: data.ts,
        });
      }
    } catch (e) {
      console.error(e);
    }
  };
  eventSource.onerror = () => {
    renderStatus('SSE 断开，正在重试...');
    eventSource.close();
    if (reconnectTimer) {
      clearTimeout(reconnectTimer);
    }
    reconnectTimer = setTimeout(() => {
      catchupOps().finally(() => connectSSE());
    }, 3000);
  };
  eventSource.onopen = () => {
    renderStatus(`团队：${currentTeam} · 日期：${currentDay} · 实时连接正常`);
  };
}

function applyOp(op) {
  const version = (op.base_version ?? 0) + 1;
  cells.set(op.emp, {
    value: op.new_value,
    version,
    updated_at: op.ts,
    updated_by: op.user_id,
  });
  if (!employees.includes(op.emp)) {
    employees.push(op.emp);
  }
  renderTable();
}

function catchupOps() {
  if (syncing) {
    return Promise.resolve();
  }
  syncing = true;
  return fetch(`${API_BASE}/sync_ops.php?team=${encodeURIComponent(currentTeam)}&day=${encodeURIComponent(currentDay)}&since_ts=${lastTs}`, {
    credentials: 'include',
  })
    .then((res) => res.json())
    .then((data) => {
      if (Array.isArray(data.ops)) {
        data.ops.forEach((op) => {
          lastTs = Math.max(lastTs, op.ts);
          applyOp(op);
        });
      }
    })
    .catch((err) => console.error(err))
    .finally(() => {
      syncing = false;
    });
}

function closeDropdown() {
  if (openDropdown) {
    openDropdown.remove();
    openDropdown = null;
    if (dropdownButton) {
      dropdownButton.setAttribute('aria-expanded', 'false');
      if (document.contains(dropdownButton)) {
        dropdownButton.focus();
      }
    }
    dropdownButton = null;
    document.removeEventListener('mousedown', handleOutsideClick, true);
    document.removeEventListener('keydown', handleKeyDown, true);
  }
}

function handleOutsideClick(event) {
  if (openDropdown && !openDropdown.contains(event.target)) {
    closeDropdown();
  }
}

function handleKeyDown(event) {
  if (event.key === 'Escape') {
    event.preventDefault();
    closeDropdown();
  }
}

function toggleDropdown(trigger, emp) {
  if (openDropdown) {
    closeDropdown();
  }
  dropdownButton = trigger;
  const panel = document.createElement('div');
  panel.className = 'dropdown-panel';
  panel.setAttribute('role', 'listbox');
  panel.setAttribute('aria-label', `${emp} 班次选项`);
  const rect = trigger.getBoundingClientRect();
  panel.style.top = `${rect.bottom + window.scrollY}px`;
  panel.style.left = `${rect.left + window.scrollX}px`;

  SHIFT_TYPES.forEach((value, index) => {
    const option = document.createElement('button');
    option.type = 'button';
    option.className = 'dropdown-option';
    option.textContent = value;
    option.setAttribute('role', 'option');
    option.setAttribute('aria-selected', cells.get(emp)?.value === value ? 'true' : 'false');
    option.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();
      closeDropdown();
      updateCell(emp, value);
    });
    option.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        updateCell(emp, value);
        closeDropdown();
      }
    });
    panel.appendChild(option);
    if (index === 0) {
      setTimeout(() => option.focus(), 0);
    }
  });

  document.body.appendChild(panel);
  openDropdown = panel;
  trigger.setAttribute('aria-expanded', 'true');
  document.addEventListener('mousedown', handleOutsideClick, true);
  document.addEventListener('keydown', handleKeyDown, true);
}

reloadBtn.addEventListener('click', (event) => {
  event.preventDefault();
  event.stopPropagation();
  closeDropdown();
  loadSchedule();
});

teamInput.addEventListener('change', () => loadSchedule());
dayInput.addEventListener('change', () => loadSchedule());

window.addEventListener('beforeunload', () => {
  lockTimers.forEach((timer, emp) => {
    clearTimeout(timer);
    fetch(`${API_BASE}/lock.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      keepalive: true,
      body: JSON.stringify({ action: 'release', team: currentTeam, day: currentDay, emp }),
    }).catch(() => {});
  });
  if (eventSource) {
    eventSource.close();
  }
});

window.addEventListener('visibilitychange', () => {
  if (document.hidden) {
    lockTimers.forEach((timer) => clearTimeout(timer));
  }
});

loadSchedule();
