/*
 * DeepSeek 自动排班前端集成（完整单文件）
 * 用法：
 *  1) 把本文件保存为 /static/ai-propose.js 并在页面底部引入：
 *     <script src="./static/ai-propose.js"></script>
 *  2) 若你已有全局状态 window.ScheduleState（推荐），本脚本会自动使用；
 *     否则尝试解析 #schedule-table 表格（首列=员工ID，表头=日期，单元格=班次文本'白/中1/中2/夜/休'）。
 *  3) 点击右下角悬浮按钮 → 调后端 /api/ai_propose.php 获取 ops → 本地硬校验 → 套用。
 *
 * 可在 CONFIG 中小改以适配你的页面结构。
 */
(function(){
  const CONFIG = {
    apiPath: '/api/ai_propose.php',
    modePreference: 'auto', // 'auto' | 'state' | 'table'
    ui: { cornerBtn: true, previewBeforeApply: true },
    // 表格模式（当没有 window.ScheduleState 时使用）
    table: {
      selector: '#schedule-table',       // 你的排班表格 id/class
      headerRowIndex: 0,                 // 表头所在行索引（从 0 开始）
      firstDataRowIndex: 1,              // 数据起始行（员工行）
      firstDataColIndex: 1,              // 数据起始列（日期列，从 1 表示第一列是员工ID）
      empIdColIndex: 0,                  // 员工ID所在列
      dateFormat: 'auto'                 // 'auto'：从表头文本自动识别 YYYY-MM-DD / MM-DD / M/D 等
    },
    // 校验选项（根据需要微调）
    validate: {
      forbidMid1ToDay: true,                 // 禁止同日 "中1" → "白"
      nightRest2Days: true,                  // 夜班后休 2 天，且禁中2（在本次变更可检查到的范围内）
      mid2NextDayRest: true,                 // 中2 次日必须休
      maxConsecutiveWorkdays: 6,             // 连续上班 ≤ 6 天
      respectRestPref: false                 // 是否强制尊重休息偏好（休12/23/34/56/71）
    },
    // 文本标准化映射
    shiftMap: {
      '白班':'白','白':'白',
      '中班':'中1','中1':'中1','中二':'中2','中2':'中2','中Ⅱ':'中2',
      '夜班':'夜','夜':'夜',
      '休息':'休','休':'休','请假':'休','调休':'休'
    }
  };

  // ===== 工具函数 =====
  const $ = sel => document.querySelector(sel);
  const $$ = sel => Array.from(document.querySelectorAll(sel));
  const clamp = (n,min,max)=>Math.max(min,Math.min(max,n));
  const toISO = d => d.toISOString().slice(0,10);
  const parseDateAuto = (txt, baseYear) => {
    txt = String(txt).trim().replace(/[\.年]/g,'-').replace(/[\/]/g,'-');
    // 支持：YYYY-MM-DD | MM-DD | M-D | MM/DD | M/D
    const m = txt.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
    if (m) {
      const y = parseInt(m[1],10), mo=parseInt(m[2],10), da=parseInt(m[3],10);
      return new Date(y, mo-1, da);
    }
    const m2 = txt.match(/^(\d{1,2})-(\d{1,2})$/);
    if (m2) {
      const mo=parseInt(m2[1],10), da=parseInt(m2[2],10);
      const y = baseYear || new Date().getFullYear();
      return new Date(y, mo-1, da);
    }
    // 如果是“10月01日”之类
    const m3 = txt.match(/^(\d{1,2})月(\d{1,2})日$/);
    if (m3) {
      const mo=parseInt(m3[1],10), da=parseInt(m3[2],10);
      const y = baseYear || new Date().getFullYear();
      return new Date(y, mo-1, da);
    }
    // 最后看是否是 ISO
    const d = new Date(txt);
    return isNaN(d.getTime()) ? null : d;
  };
  const weekday = d => (d.getDay() === 0 ? 7 : d.getDay()); // 1~7 (Mon=1)
  const isWork = s => (s !== '休' && s !== '' && s != null);

  function stdShift(txt){
    const t = String(txt||'').trim();
    return CONFIG.shiftMap[t] || t; // 未映射的保持原样
  }

  function deepClone(obj){ return JSON.parse(JSON.stringify(obj||{})); }

  // ===== 状态抽象层：State 模式 & Table 模式 =====
  function detectMode(){
    if (CONFIG.modePreference === 'state' && window.ScheduleState) return 'state';
    if (CONFIG.modePreference === 'table' && $(CONFIG.table.selector)) return 'table';
    if (window.ScheduleState) return 'state';
    if ($(CONFIG.table.selector)) return 'table';
    return 'none';
  }

  function getStateAPI(){
    const mode = detectMode();
    if (mode === 'state') return buildStateAPI();
    if (mode === 'table') return buildTableAPI();
    throw new Error('未检测到可用的数据源：请提供 window.ScheduleState 或 #schedule-table');
  }

  // —— A. 使用全局状态（推荐）——
  function buildStateAPI(){
    const S = window.ScheduleState;
    function collect(){
      return {
        start: S.start,
        end: S.end,
        employees: S.employees.slice(),
        data: deepClone(S.grid),
        restPrefs: deepClone(S.restPrefs||{}),
        nightRules: deepClone(S.nightRules||{minInterval:3,rest2AfterNight:{enabled:true}}),
        midRatio: 0.5,
        mixedCycleMaxRatio: 0.25,
        max_ops: 120,
        mode: 'reason'
      };
    }
    function getShift(day, emp){ return stdShift((S.grid[day]||{})[emp]||''); }
    function setShift(day, emp, val){ if (!S.grid[day]) S.grid[day] = {}; S.grid[day][emp] = val; if (typeof window.renderGrid==='function') window.renderGrid(); }
    function listDays(){ return Object.keys(S.grid).sort(); }
    return { mode:'state', collect, getShift, setShift, listDays, employees:()=>S.employees.slice(), restPrefs:()=>S.restPrefs||{} };
  }

  // —— B. 解析表格 ——
  function buildTableAPI(){
    const tb = $(CONFIG.table.selector);
    if (!tb) throw new Error('未找到表格：' + CONFIG.table.selector);

    const rows = Array.from(tb.rows);
    const header = rows[CONFIG.table.headerRowIndex];
    const baseYear = new Date().getFullYear();

    // 解析日期列
    const dayKeys = [];
    for (let c = CONFIG.table.firstDataColIndex; c < header.cells.length; c++){
      const txt = header.cells[c].innerText || header.cells[c].textContent || '';
      const d = parseDateAuto(txt, baseYear);
      if (!d) throw new Error('无法识别表头日期：' + txt);
      dayKeys.push(toISO(d));
    }

    // 解析员工ID
    const empIds = [];
    for (let r = CONFIG.table.firstDataRowIndex; r < rows.length; r++){
      const empCell = rows[r].cells[CONFIG.table.empIdColIndex];
      if (!empCell) continue;
      const id = String(empCell.innerText||empCell.textContent||'').trim();
      if (id) empIds.push(id);
    }

    // 构造 grid 读写
    const grid = {}; // 懒读
    function ensureGrid(){
      if (Object.keys(grid).length) return;
      for (let r = CONFIG.table.firstDataRowIndex; r < rows.length; r++){
        const empCell = rows[r].cells[CONFIG.table.empIdColIndex];
        if (!empCell) continue;
        const emp = String(empCell.innerText||empCell.textContent||'').trim();
        if (!emp) continue;
        for (let c = CONFIG.table.firstDataColIndex; c < header.cells.length; c++){
          const day = dayKeys[c - CONFIG.table.firstDataColIndex];
          const td = rows[r].cells[c];
          const raw = td ? (td.innerText||td.textContent||'') : '';
          const val = stdShift(raw);
          if (!grid[day]) grid[day] = {};
          grid[day][emp] = val;
        }
      }
    }

    function collect(){
      ensureGrid();
      return {
        start: dayKeys[0],
        end: dayKeys[dayKeys.length-1],
        employees: empIds.slice(),
        data: deepClone(grid),
        restPrefs: {},
        nightRules: {minInterval:3,rest2AfterNight:{enabled:true}},
        midRatio: 0.5,
        mixedCycleMaxRatio: 0.25,
        max_ops: 120,
        mode: 'reason'
      };
    }

    function getCell(day, emp){
      const dIdx = dayKeys.indexOf(day);
      if (dIdx === -1) return null;
      for (let r = CONFIG.table.firstDataRowIndex; r < rows.length; r++){
        const empCell = rows[r].cells[CONFIG.table.empIdColIndex];
        const id = String(empCell.innerText||empCell.textContent||'').trim();
        if (id === emp) return rows[r].cells[CONFIG.table.firstDataColIndex + dIdx] || null;
      }
      return null;
    }

    function getShift(day, emp){ ensureGrid(); return stdShift((grid[day]||{})[emp]||''); }

    function setShift(day, emp, val){
      ensureGrid();
      if (!grid[day]) grid[day] = {};
      grid[day][emp] = val;
      const cell = getCell(day, emp);
      if (cell) { cell.textContent = val; }
    }

    function listDays(){ return dayKeys.slice(); }

    return { mode:'table', collect, getShift, setShift, listDays, employees:()=>empIds.slice(), restPrefs:()=>({}) };
  }

  // ===== 业务：校验规则 =====
  function hardValidate(api, day, emp, from, to){
    const V = CONFIG.validate;
    to = stdShift(to); from = stdShift(from);
    if (to === from) return true; // 无变化

    // 1) 禁止同日 "中1" → "白"
    if (V.forbidMid1ToDay && from === '中1' && to === '白') return false;

    // 2) 中2 次日必须休
    if (V.mid2NextDayRest && to === '中2'){
      const nextDay = nextISO(day);
      const ns = api.getShift(nextDay, emp);
      if (ns && ns !== '休') return false;
    }

    // 3) 夜班后休 2 天，且这两天不得中2
    if (V.nightRest2Days && to === '夜'){
      const d1 = nextISO(day), d2 = nextISO(d1);
      const s1 = api.getShift(d1, emp), s2 = api.getShift(d2, emp);
      if ((s1 && s1 !== '休') || (s2 && s2 !== '休')) return false;
      if (s1 === '中2' || s2 === '中2') return false;
    }

    // 4) 连续上班 ≤ max
    if (V.maxConsecutiveWorkdays){
      const days = api.listDays();
      const idx = days.indexOf(day);
      if (idx === -1) return true;

      // 构造修改后的当日状态
      const newIsWork = isWork(to);

      // 向前统计
      let streak = newIsWork ? 1 : 0;
      for (let i = idx-1; i >= 0; i--) {
        const s = api.getShift(days[i], emp);
        if (isWork(s)) streak++; else break;
      }
      // 向后统计（不改变未来日的现状）
      for (let i = idx+1; i < days.length; i++) {
        const s = api.getShift(days[i], emp);
        if (isWork(s)) streak++; else break;
      }
      if (streak > V.maxConsecutiveWorkdays) return false;
    }

    // 5) （可选）尊重休息偏好：在偏好日阻止安排非“休”
    if (V.respectRestPref){
      const prefs = api.restPrefs();
      const p = prefs[emp]; // e.g. '56'
      if (p && isWork(to)){
        const d = new Date(day);
        const wd = weekday(d); // 1~7
        if (p.indexOf(String(wd)) !== -1) return false;
      }
    }

    return true;
  }

  function nextISO(iso){ const d = new Date(iso); d.setDate(d.getDate()+1); return toISO(d); }

  // ===== 调后端 =====
  async function callAI(payload){
    const resp = await fetch(CONFIG.apiPath, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    });
    if (!resp.ok) throw new Error('AI 接口失败：HTTP '+resp.status);
    return await resp.json();
  }

  // ===== UI：悬浮按钮 & 预览 =====
  function ensureStyles(){
    if ($('#ai-propose-style')) return;
    const css = document.createElement('style');
    css.id = 'ai-propose-style';
    css.textContent = `
      .ai-btn{position:fixed;right:16px;bottom:16px;z-index:99999;padding:10px 14px;border-radius:12px;border:none;cursor:pointer;background:#111;color:#fff;box-shadow:0 6px 16px rgba(0,0,0,.2)}
      .ai-modal{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:999999;display:flex;align-items:center;justify-content:center}
      .ai-card{background:#fff;max-width:720px;width:92%;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
      .ai-card header{padding:14px 16px;font-weight:700;border-bottom:1px solid #eee}
      .ai-card .body{padding:12px 16px;max-height:60vh;overflow:auto}
      .ai-card .foot{padding:12px 16px;display:flex;gap:8px;justify-content:flex-end;border-top:1px solid #eee}
      .ai-op{display:flex;gap:8px;align-items:center;padding:6px 0;border-bottom:1px dashed #eee;font-size:13px}
      .ai-badge{display:inline-block;padding:2px 6px;border-radius:999px;background:#eee;font-size:12px}
      .ai-ok{color:#0a7}
      .ai-skip{color:#c33}
    `;
    document.head.appendChild(css);
  }

  function showPreview(ops, validator){
    const wrap = document.createElement('div');
    wrap.className = 'ai-modal';
    wrap.innerHTML = `
      <div class="ai-card">
        <header>AI 建议预览（共 ${ops.length} 条）</header>
        <div class="body"></div>
        <div class="foot">
          <button id="ai-cancel" class="ai-btn" style="position:static;background:#666">取消</button>
          <button id="ai-apply" class="ai-btn" style="position:static">套用</button>
        </div>
      </div>`;
    const list = wrap.querySelector('.body');
    const items = ops.map(op=>{
      const can = validator(op);
      const row = document.createElement('div');
      row.className='ai-op';
      row.innerHTML = `
        <span class="ai-badge ${can?'ai-ok':'ai-skip'}">${can?'可用':'跳过'}</span>
        <span>${op.day}</span>
        <span>·</span>
        <span>${op.emp}</span>
        <span>·</span>
        <span>${op.from||''} → <b>${op.to}</b></span>
        <span style="opacity:.7">${op.why?('（'+op.why+'）'):''}</span>
      `;
      list.appendChild(row);
      return {op, can};
    });
    document.body.appendChild(wrap);

    return new Promise(resolve=>{
      wrap.querySelector('#ai-cancel').onclick = ()=>{ document.body.removeChild(wrap); resolve(null); };
      wrap.querySelector('#ai-apply').onclick = ()=>{ document.body.removeChild(wrap); resolve(items); };
      wrap.onclick = (e)=>{ if(e.target===wrap){ document.body.removeChild(wrap); resolve(null); } };
    });
  }

  async function main(){
    ensureStyles();
    if (CONFIG.ui.cornerBtn){
      const btn = document.createElement('button');
      btn.className = 'ai-btn';
      btn.id = 'btn-deepseek-propose';
      btn.textContent = '用 DeepSeek 生成并套用建议';
      btn.onclick = async ()=>{
        try{
          btn.disabled = true; btn.textContent = '生成中…';
          const api = getStateAPI();
          const payload = api.collect();
          const {ops = [], note = ''} = await callAI(payload);

          // 预览 + 校验
          const check = (x)=> hardValidate(api, x.day, x.emp, api.getShift(x.day,x.emp)||'', x.to);
          let applyList = ops.map(x=>({op:x, can:check(x)}));

          if (CONFIG.ui.previewBeforeApply){
            const chosen = await showPreview(ops, check);
            if (!chosen) { btn.disabled=false; btn.textContent='用 DeepSeek 生成并套用建议'; return; }
            applyList = chosen;
          }

          // 套用
          let ok=0, skip=0;
          for (const {op,can} of applyList){
            if (!can) { skip++; continue; }
            const cur = api.getShift(op.day, op.emp)||'';
            if (!hardValidate(api, op.day, op.emp, cur, op.to)) { skip++; continue; }
            api.setShift(op.day, op.emp, stdShift(op.to));
            ok++;
          }
          alert(`完成：通过 ${ok} 条，跳过 ${skip} 条。\n备注：${note||'无'}`);
        }catch(err){
          console.error(err);
          alert('失败：'+(err.message||err));
        }finally{
          btn.disabled = false; btn.textContent = '用 DeepSeek 生成并套用建议';
        }
      };
      document.body.appendChild(btn);
    }
  }

  // 延迟到文档就绪
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', main); else main();

})();
