// 协同排班前端脚本，负责：
// 1. 渲染排班表、维护版本
// 2. 处理软锁、写入、冲突回滚
// 3. 建立 SSE 通道，实时同步其他协作者操作
// 4. 修复单元格下拉导致页面回到顶部的 BUG：使用 button + fixed 浮层避免滚动抖动

(() => {
    const employees = ['张三', '李四', '王五', '赵六', '钱七'];
    const shifts = ['白', '中1', '中2', '夜', '休'];
    const state = {
        team: '版权组',
        day: new Date().toISOString().slice(0, 10),
        cells: new Map(), // key: emp, value: {value, version}
        locks: new Map(), // key: emp, value: {timerId, lockUntil}
        eventSource: null,
        lastTs: 0,
        dropdown: null,
        dropdownTrigger: null,
        reconnectTimer: null,
    };

    const dom = {
        teamInput: document.getElementById('team-input'),
        dayInput: document.getElementById('day-input'),
        reloadBtn: document.getElementById('reload-btn'),
        snapshotBtn: document.getElementById('snapshot-btn'),
        tableBody: document.getElementById('schedule-body'),
        loadingBar: document.getElementById('loading-bar'),
        toastContainer: document.getElementById('toast-container'),
    };

    dom.dayInput.value = state.day;

    function setLoading(progress) {
        dom.loadingBar.style.width = `${progress}%`;
        if (progress >= 100) {
            setTimeout(() => {
                dom.loadingBar.style.width = '0';
            }, 600);
        }
    }

    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = 'toast';
        toast.textContent = message;
        toast.dataset.type = type;
        dom.toastContainer.appendChild(toast);
        setTimeout(() => {
            toast.remove();
        }, 4000);
    }

    function buildTable() {
        dom.tableBody.innerHTML = '';
        employees.forEach((emp) => {
            const row = document.createElement('tr');
            const nameCell = document.createElement('th');
            nameCell.scope = 'row';
            nameCell.textContent = emp;

            const valueCell = document.createElement('td');
            valueCell.dataset.emp = emp;
            valueCell.tabIndex = -1;

            const valueText = document.createElement('div');
            valueText.className = 'cell-value';
            valueText.textContent = '未排';

            const meta = document.createElement('div');
            meta.className = 'cell-meta';
            meta.textContent = '版本：0';

            const actions = document.createElement('div');
            actions.className = 'cell-actions';

            const trigger = document.createElement('button');
            trigger.type = 'button';
            trigger.className = 'dropdown-trigger';
            trigger.setAttribute('aria-haspopup', 'listbox');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.title = '调整班次';
            trigger.innerHTML = '▼';

            trigger.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                openDropdown(trigger, valueCell.dataset.emp);
            });

            actions.appendChild(trigger);
            valueCell.appendChild(valueText);
            valueCell.appendChild(meta);
            valueCell.appendChild(actions);

            row.appendChild(nameCell);
            row.appendChild(valueCell);
            dom.tableBody.appendChild(row);
        });
    }

    function getCellState(emp) {
        if (!state.cells.has(emp)) {
            state.cells.set(emp, { value: '未排', version: 0 });
        }
        return state.cells.get(emp);
    }

    async function fetchCell(emp) {
        const params = new URLSearchParams({
            team: state.team,
            day: state.day,
            emp,
        });
        const res = await fetch(`/api/get_cell.php?${params.toString()}`, {
            credentials: 'include',
        });
        if (!res.ok) {
            throw new Error('获取单元格失败');
        }
        const data = await res.json();
        const cell = getCellState(emp);
        cell.value = data.value ?? '未排';
        cell.version = data.version ?? 0;
        updateCellUI(emp);
    }

    function updateCellUI(emp) {
        const cell = dom.tableBody.querySelector(`td[data-emp="${emp}"]`);
        if (!cell) return;
        const cellState = getCellState(emp);
        const valueText = cell.querySelector('.cell-value');
        const meta = cell.querySelector('.cell-meta');
        valueText.textContent = cellState.value;
        meta.textContent = `版本：${cellState.version}`;
    }

    function closeDropdown() {
        if (state.dropdown) {
            state.dropdown.remove();
            state.dropdown = null;
        }
        if (state.dropdownTrigger) {
            state.dropdownTrigger.setAttribute('aria-expanded', 'false');
            state.dropdownTrigger.focus();
            state.dropdownTrigger = null;
        }
        stopRenewTimer();
    }

    function stopRenewTimer(emp) {
        if (emp && state.locks.has(emp)) {
            const lockInfo = state.locks.get(emp);
            clearInterval(lockInfo.timerId);
            state.locks.delete(emp);
        }
    }

    async function acquireLock(emp) {
        try {
            const form = new FormData();
            form.append('team', state.team);
            form.append('day', state.day);
            form.append('emp', emp);
            form.append('action', 'acquire');
            const res = await fetch('/api/lock.php', {
                method: 'POST',
                body: form,
                credentials: 'include',
            });
            const data = await res.json();
            if (!res.ok || data.error) {
                throw new Error(data.message || '软锁失败');
            }
            if (data.risk) {
                showToast('注意：他人也在编辑该单元格', 'warn');
            }
            const renewId = setInterval(() => renewLock(emp), 15000);
            state.locks.set(emp, { timerId: renewId, lockUntil: data.lock_until });
        } catch (err) {
            showToast(err.message, 'error');
        }
    }

    async function renewLock(emp) {
        try {
            const form = new FormData();
            form.append('team', state.team);
            form.append('day', state.day);
            form.append('emp', emp);
            form.append('action', 'renew');
            await fetch('/api/lock.php', {
                method: 'POST',
                body: form,
                credentials: 'include',
            });
        } catch (err) {
            console.warn('续约锁失败', err);
        }
    }

    async function releaseLock(emp) {
        try {
            const form = new FormData();
            form.append('team', state.team);
            form.append('day', state.day);
            form.append('emp', emp);
            form.append('action', 'release');
            await fetch('/api/lock.php', {
                method: 'POST',
                body: form,
                credentials: 'include',
            });
        } catch (err) {
            console.warn('释放锁失败', err);
        } finally {
            stopRenewTimer(emp);
        }
    }

    function openDropdown(trigger, emp) {
        if (state.dropdownTrigger === trigger) {
            closeDropdown();
            return;
        }
        closeDropdown();
        state.dropdownTrigger = trigger;
        trigger.setAttribute('aria-expanded', 'true');

        const dropdown = document.createElement('div');
        dropdown.className = 'shift-dropdown';
        dropdown.setAttribute('role', 'listbox');
        dropdown.dataset.emp = emp;

        shifts.forEach((shiftValue, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.setAttribute('role', 'option');
            option.textContent = shiftValue;
            option.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                applyShift(emp, shiftValue);
                closeDropdown();
            });
            dropdown.appendChild(option);
            if (index === 0) {
                setTimeout(() => option.focus(), 0);
            }
        });

        const rect = trigger.getBoundingClientRect();
        dropdown.style.top = `${rect.bottom + window.scrollY}px`;
        dropdown.style.left = `${rect.left + window.scrollX}px`;

        document.body.appendChild(dropdown);
        state.dropdown = dropdown;

        acquireLock(emp);
    }

    async function applyShift(emp, value) {
        const cellState = getCellState(emp);
        const opId = `${emp}_${Date.now()}_${Math.random().toString(16).slice(2, 8)}`;
        const form = new FormData();
        form.append('team', state.team);
        form.append('day', state.day);
        form.append('emp', emp);
        form.append('new_value', value);
        form.append('base_version', String(cellState.version));
        form.append('op_id', opId);

        try {
            setLoading(45);
            const res = await fetch('/api/update_cell.php', {
                method: 'POST',
                body: form,
                credentials: 'include',
            });
            const data = await res.json();
            setLoading(100);
            if (!res.ok || data.error) {
                throw new Error(data.message || '写入失败');
            }
            if (data.applied) {
                cellState.value = value;
                cellState.version = data.version;
                state.lastTs = Math.max(state.lastTs, data.ts || Date.now());
                updateCellUI(emp);
                showToast(`已将 ${emp} 调整为 ${value}`);
            } else if (data.reason === 'conflict') {
                showToast('版本冲突，已回滚', 'warn');
                await fetchCell(emp);
            }
        } catch (err) {
            showToast(err.message, 'error');
        } finally {
            releaseLock(emp);
        }
    }

    function subscribeSSE() {
        if (state.eventSource) {
            state.eventSource.close();
            state.eventSource = null;
        }
        const params = new URLSearchParams({
            team: state.team,
            day: state.day,
            since_ts: state.lastTs,
        });
        const es = new EventSource(`/api/sse.php?${params.toString()}`);
        es.onmessage = (event) => {
            try {
                const payload = JSON.parse(event.data);
                if (payload.type === 'cell.update') {
                    state.lastTs = Math.max(state.lastTs, payload.ts);
                    const cellState = getCellState(payload.emp);
                    cellState.value = payload.value;
                    cellState.version = Math.max(cellState.version, (payload.base_version || 0) + 1);
                    updateCellUI(payload.emp);
                    if (state.dropdown && state.dropdown.dataset.emp === payload.emp) {
                        showToast('提示：他人已更新该单元格', 'warn');
                    }
                }
            } catch (err) {
                console.warn('解析 SSE 失败', err);
            }
        };
        es.onerror = () => {
            es.close();
            state.eventSource = null;
            showToast('实时连接断开，尝试补差', 'warn');
            if (state.reconnectTimer) {
                clearTimeout(state.reconnectTimer);
            }
            state.reconnectTimer = setTimeout(() => {
                syncOps().finally(() => subscribeSSE());
            }, 3000);
        };
        state.eventSource = es;
    }

    async function syncOps() {
        try {
            const params = new URLSearchParams({
                team: state.team,
                day: state.day,
                since_ts: state.lastTs,
            });
            const res = await fetch(`/api/sync_ops.php?${params.toString()}`, { credentials: 'include' });
            if (!res.ok) return;
            const data = await res.json();
            data.ops.forEach((op) => {
                state.lastTs = Math.max(state.lastTs, op.ts);
                const cellState = getCellState(op.emp);
                cellState.value = op.new_value;
                cellState.version = Math.max(cellState.version, op.base_version + 1);
                updateCellUI(op.emp);
            });
        } catch (err) {
            console.warn('补差失败', err);
        }
    }

    async function loadSchedule() {
        state.team = dom.teamInput.value.trim() || '版权组';
        state.day = dom.dayInput.value || state.day;
        state.cells.clear();
        setLoading(10);
        await Promise.all(employees.map((emp) => fetchCell(emp).catch(() => {})));
        setLoading(100);
        await syncOps();
        subscribeSSE();
    }

    async function createSnapshot() {
        const params = new URLSearchParams({
            action: 'create',
            team: state.team,
            day: state.day,
            note: `手动快照_${new Date().toLocaleString('zh-CN')}`,
        });
        const res = await fetch(`/api/snapshot.php?${params.toString()}`, {
            method: 'POST',
            credentials: 'include',
        });
        const data = await res.json();
        if (!res.ok || data.error) {
            showToast(data.message || '快照失败', 'error');
            return;
        }
        showToast('快照成功');
    }

    window.addEventListener('click', (event) => {
        if (state.dropdown && !state.dropdown.contains(event.target) && state.dropdownTrigger && event.target !== state.dropdownTrigger) {
            const emp = state.dropdown.dataset.emp;
            closeDropdown();
            releaseLock(emp);
        }
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && state.dropdown) {
            const emp = state.dropdown.dataset.emp;
            closeDropdown();
            releaseLock(emp);
        }
    });

    window.addEventListener('beforeunload', () => {
        employees.forEach((emp) => releaseLock(emp));
    });

    dom.reloadBtn.addEventListener('click', () => {
        loadSchedule();
    });
    dom.snapshotBtn.addEventListener('click', () => {
        createSnapshot();
    });

    buildTable();
    loadSchedule();
})();
