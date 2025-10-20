const AutoScheduler = (() => {
  const globalScope = typeof window !== 'undefined' ? window : globalThis;
  const SHIFT_TYPES = Array.isArray(globalScope?.SHIFT_TYPES) && globalScope.SHIFT_TYPES.length
    ? globalScope.SHIFT_TYPES
    : ['白', '中1', '中2', '夜', '休'];
  const SHIFT_WORK_TYPES = SHIFT_TYPES.filter(shift => shift && shift !== '休');
  const REST_PAIRS = ['12', '23', '34', '56', '71'];

  function fmt(date) {
    return new Date(date.getTime() - date.getTimezoneOffset() * 60000)
      .toISOString()
      .slice(0, 10);
  }

  function enumerateDates(start, end) {
    const out = [];
    let d = new Date(start);
    const e = new Date(end);
    while (d <= e) {
      out.push(fmt(d));
      d.setDate(d.getDate() + 1);
    }
    return out;
  }

  function dateAdd(ymd, delta) {
    const d = new Date(ymd);
    d.setDate(d.getDate() + delta);
    return fmt(d);
  }

  const isWork = v => !!(v && v !== '休');

  function getVal(data, day, emp) {
    return (data[day] || {})[emp] || '';
  }

  function setVal(map, day, emp, val) {
    const row = { ...(map[day] || {}) };
    row[emp] = val;
    map[day] = row;
  }

  function countByEmpInRange(employees, data, start, end) {
    const dates = enumerateDates(start, end);
    const counter = {};
    for (const e of employees) {
      counter[e] = { 总: 0 };
      for (const s of SHIFT_WORK_TYPES) {
        counter[e][s] = 0;
      }
    }
    for (const d of dates) {
      const row = data[d] || {};
      for (const e of employees) {
        const v = row[e] || '';
        if (!v || v === '休') continue;
        counter[e]['总']++;
        if (SHIFT_WORK_TYPES.includes(v)) counter[e][v]++;
      }
    }
    return counter;
  }

  function countRunLeft(data, day, emp) {
    let c = 0;
    for (let i = -1; i >= -10; i--) {
      const ymd = dateAdd(day, i);
      const v = getVal(data, ymd, emp);
      if (isWork(v)) c++;
      else break;
    }
    return c;
  }

  function countRunRight(data, day, emp) {
    let c = 0;
    for (let i = 1; i <= 10; i++) {
      const ymd = dateAdd(day, i);
      const v = getVal(data, ymd, emp);
      if (isWork(v)) c++;
      else break;
    }
    return c;
  }

  function wouldExceed6(data, emp, day, newV) {
    if (!isWork(newV)) return false;
    const L = countRunLeft(data, day, emp);
    const R = countRunRight(data, day, emp);
    return L + 1 + R > 6;
  }

  const normalizeRestPair = pair => (pair === '17' ? '71' : pair);

  function sanitizeRestPairValue(val) {
    if (!val) return '';
    const digits = String(val).replace(/\D/g, '');
    if (digits.length !== 2) return '';
    const normalized = normalizeRestPair(digits);
    return REST_PAIRS.includes(normalized) ? normalized : digits;
  }

  const pairToSet = p => {
    const cleaned = sanitizeRestPairValue(p);
    if (cleaned.length !== 2) return new Set();
    const map = { '1': 1, '2': 2, '3': 3, '4': 4, '5': 5, '6': 6, '7': 7 };
    const set = new Set();
    const first = map[cleaned[0]];
    const second = map[cleaned[1]];
    if (typeof first === 'number') set.add(first);
    if (typeof second === 'number') set.add(second);
    return set;
  };

  const dayToW = day => ((new Date(day).getDay() + 6) % 7) + 1;

  function isRestDayForEmp(restPrefs, emp, day) {
    const p = restPrefs?.[emp];
    if (!p) return false;
    const set = pairToSet(p);
    const w = dayToW(day);
    return set.has(w);
  }

  function buildWhiteFiveTwo({ employees, data, start, end, restPrefs }) {
    const dates = enumerateDates(start, end);
    const next = { ...data };
    for (const d of dates) {
      const r = { ...(next[d] || {}) };
      for (const e of employees) {
        const cur = r[e] || '';
        if (cur === '夜' || cur === '中2') continue;
        r[e] = isRestDayForEmp(restPrefs, e, d) ? '休' : '白';
      }
      next[d] = r;
    }
    return next;
  }

  function buildWorkBlocks(data, emp, start, end) {
    const days = enumerateDates(start, end);
    const blocks = [];
    let cur = [];
    for (const d of days) {
      const v = getVal(data, d, emp);
      const work = v !== '休' && v !== '夜' && v !== '中2';
      if (work) {
        cur.push(d);
      } else if (cur.length) {
        blocks.push(cur);
        cur = [];
      }
    }
    if (cur.length) blocks.push(cur);
    return blocks;
  }

  function cyclesForEmp(data, emp, start, end) {
    const blocks = buildWorkBlocks(data, emp, start, end);
    const cycles = [];
    for (const b of blocks) {
      const k = Math.floor(b.length / 5);
      for (let i = 0; i < k; i++) {
        cycles.push(b.slice(i * 5, i * 5 + 5));
      }
    }
    return cycles;
  }

  function isRightEdgeWhite(data, day, emp) {
    const v = getVal(data, day, emp);
    if (v !== '白') return false;
    const nextDay = dateAdd(day, 1);
    const nxt = getVal(data, nextDay, emp);
    return nxt !== '白';
  }

  function isLeftEdgeMid(data, day, emp) {
    const v = getVal(data, day, emp);
    if (v !== '中1') return false;
    const prevDay = dateAdd(day, -1);
    const prev = getVal(data, prevDay, emp);
    return prev !== '中1';
  }

  function dailyMidCount(data, day, employees) {
    const row = data[day] || {};
    let c = 0;
    for (const e of employees) {
      if (row[e] === '中1') c++;
    }
    return c;
  }

  function dailyWhiteCount(data, day, employees) {
    const row = data[day] || {};
    let c = 0;
    for (const e of employees) {
      if (row[e] === '白') c++;
    }
    return c;
  }

  function empMidCountInRange(data, emp, start, end) {
    let c = 0;
    for (const d of enumerateDates(start, end)) {
      if (getVal(data, d, emp) === '中1') c++;
    }
    return c;
  }

  function empWhiteCountInRange(data, emp, start, end) {
    let c = 0;
    for (const d of enumerateDates(start, end)) {
      if (getVal(data, d, emp) === '白') c++;
    }
    return c;
  }

  function mixedCyclesCount(data, emp, start, end) {
    const cycles = cyclesForEmp(data, emp, start, end);
    let c = 0;
    for (const cyc of cycles) {
      let hasW = false;
      let hasM = false;
      for (const d of cyc) {
        const v = getVal(data, d, emp);
        if (v === '白') hasW = true;
        if (v === '中1') hasM = true;
      }
      if (hasW && hasM) c++;
    }
    return c;
  }

  function longestRun(data, emp, start, end) {
    let max = 0;
    let cur = 0;
    for (const d of enumerateDates(start, end)) {
      if (isWork(getVal(data, d, emp))) {
        cur++;
        max = Math.max(max, cur);
      } else {
        cur = 0;
      }
    }
    return max;
  }

  function trySetVal(next, day, emp, newV, { start, end, mixMaxRatio } = {}) {
    const prev = getVal(next, day, emp);
    if (prev === newV) return true;
    if (wouldExceed6(next, emp, day, newV)) return false;
    const prevDay = dateAdd(day, -1);
    const nextDay = dateAdd(day, 1);
    if (newV === '白' && getVal(next, prevDay, emp) === '中1') return false;
    if (newV === '中1' && getVal(next, nextDay, emp) === '白') return false;
    const tmp = JSON.parse(JSON.stringify(next));
    setVal(tmp, day, emp, newV);
    if (typeof mixMaxRatio === 'number') {
      const tot = cyclesForEmp(tmp, emp, start, end).length;
      const allow = Math.ceil(tot * mixMaxRatio - 1e-9);
      const mixed = mixedCyclesCount(tmp, emp, start, end);
      if (mixed > allow) return false;
    }
    setVal(next, day, emp, newV);
    return true;
  }

  function repairNoMidToWhite({ data, employees, start, end }) {
    const next = { ...data };
    for (const e of employees) {
      const ds = enumerateDates(start, end);
      for (let i = 0; i < ds.length - 1; i++) {
        const a = ds[i];
        const b = ds[i + 1];
        const va = getVal(next, a, e);
        const vb = getVal(next, b, e);
        if (va === '中1' && vb === '白') {
          if (!wouldExceed6(next, e, b, '中1')) {
            setVal(next, b, e, '中1');
          } else {
            setVal(next, a, e, '白');
          }
        }
      }
    }
    return next;
  }

  function applyAlternateByCycle({ employees, data, start, end }, mixMaxRatio = 1) {
    const next = { ...data };
    employees.forEach((emp, idx) => {
      const startIsWhite = idx % 2 === 0;
      const cycles = cyclesForEmp(next, emp, start, end);
      cycles.forEach((cyc, ci) => {
        const shouldMid = startIsWhite ? ci % 2 === 1 : ci % 2 === 0;
        if (shouldMid) {
          for (const d of cyc) {
            if (isRightEdgeWhite(next, d, emp)) {
              trySetVal(next, d, emp, '中1', { start, end, mixMaxRatio });
            }
          }
        }
      });
    });
    return repairNoMidToWhite({ data: next, employees, start, end });
  }

  function clampDailyByRange({ employees, data, start, end, rMin = 0.3, rMax = 0.7, maxRounds = 300, mixMaxRatio = 1 }) {
    let cur = JSON.parse(JSON.stringify(data));
    const days = enumerateDates(start, end);
    for (let round = 0; round < maxRounds; round++) {
      let changed = false;
      for (const d of days) {
        const W = dailyWhiteCount(cur, d, employees);
        const M = dailyMidCount(cur, d, employees);
        const A = W + M;
        if (A === 0) continue;
        const low = Math.ceil(rMin * W);
        const high = Math.floor(rMax * W);
        if (M > high) {
          let cands = employees.filter(e => isLeftEdgeMid(cur, d, e));
          cands = cands.sort((a, b) => {
            const denomA = empWhiteCountInRange(cur, a, start, end);
            const denomB = empWhiteCountInRange(cur, b, start, end);
            const ratioA = denomA > 0 ? empMidCountInRange(cur, a, start, end) / denomA : 99;
            const ratioB = denomB > 0 ? empMidCountInRange(cur, b, start, end) / denomB : 99;
            return ratioB - ratioA;
          });
          for (const e of cands) {
            if (trySetVal(cur, d, e, '白', { start, end, mixMaxRatio })) {
              changed = true;
              if (dailyMidCount(cur, d, employees) <= high) break;
            }
          }
        } else if (M < low) {
          let cands = employees.filter(e => isRightEdgeWhite(cur, d, e));
          cands = cands.sort((a, b) => {
            const denomA = empWhiteCountInRange(cur, a, start, end);
            const denomB = empWhiteCountInRange(cur, b, start, end);
            const ratioA = denomA > 0 ? empMidCountInRange(cur, a, start, end) / denomA : 0;
            const ratioB = denomB > 0 ? empMidCountInRange(cur, b, start, end) / denomB : 0;
            return ratioA - ratioB;
          });
          for (const e of cands) {
            if (trySetVal(cur, d, e, '中1', { start, end, mixMaxRatio })) {
              changed = true;
              if (dailyMidCount(cur, d, employees) >= low) break;
            }
          }
        }
      }
      if (!changed) break;
    }
    return repairNoMidToWhite({ data: cur, employees, start, end });
  }

  function clampPersonByRange({ employees, data, start, end, pMin = 0.3, pMax = 0.7, maxRounds = 240, mixMaxRatio = 1 }) {
    let cur = JSON.parse(JSON.stringify(data));
    const days = enumerateDates(start, end);
    for (let round = 0; round < maxRounds; round++) {
      let changed = false;
      for (const e of employees) {
        const W = empWhiteCountInRange(cur, e, start, end);
        const M = empMidCountInRange(cur, e, start, end);
        if (W === 0 && M === 0) continue;
        const low = Math.ceil(pMin * Math.max(1, W));
        const high = Math.floor(pMax * Math.max(1, W));
        if (M > high) {
          for (const d of days) {
            if (isLeftEdgeMid(cur, d, e)) {
              if (trySetVal(cur, d, e, '白', { start, end, mixMaxRatio })) {
                changed = true;
                break;
              }
            }
          }
        } else if (M < low) {
          for (const d of days) {
            if (isRightEdgeWhite(cur, d, e)) {
              if (trySetVal(cur, d, e, '中1', { start, end, mixMaxRatio })) {
                changed = true;
                break;
              }
            }
          }
        }
      }
      if (!changed) break;
    }
    return repairNoMidToWhite({ data: cur, employees, start, end });
  }

  function statsForEmployee(data, emp, start, end) {
    let white = 0;
    let mid = 0;
    let mid2 = 0;
    let night = 0;
    let total = 0;
    for (const day of enumerateDates(start, end)) {
      const v = getVal(data, day, emp);
      if (!v || v === '休') continue;
      total++;
      if (v === '白') white++;
      else if (v === '中1') mid++;
      else if (v === '中2') mid2++;
      else if (v === '夜') night++;
    }
    return { white, mid, mid2, night, total };
  }

  function sortedByHistory(employees, historyProfile) {
    const totals = historyProfile?.shiftTotals || historyProfile?.shift_totals || {};
    return employees.slice().sort((a, b) => {
      const ta = totals[a]?.total ?? totals[a]?.总 ?? 0;
      const tb = totals[b]?.total ?? totals[b]?.总 ?? 0;
      if (ta !== tb) return ta - tb;
      const wa = totals[a]?.white ?? totals[a]?.白 ?? 0;
      const wb = totals[b]?.white ?? totals[b]?.白 ?? 0;
      if (wa !== wb) return wa - wb;
      return a.localeCompare(b, 'zh-CN');
    });
  }

  function adjustEmployeeSchedule({ next, emp, days, spanStart, spanEnd, targetWhite, targetMid, targetTotal, mixMaxRatio }) {
    const count = () => {
      let white = 0;
      let mid = 0;
      let total = 0;
      for (const day of days) {
        const v = getVal(next, day, emp);
        if (!v || v === '休') continue;
        total++;
        if (v === '白') white++;
        else if (v === '中1') mid++;
      }
      return { white, mid, total };
    };

    const tryConvert = (from, to) => {
      for (const day of days) {
        const v = getVal(next, day, emp);
        if (v !== from) continue;
        if (to === '休') {
          const row = { ...(next[day] || {}) };
          row[emp] = '休';
          next[day] = row;
          return true;
        }
        if (to === '白' || to === '中1') {
          if (trySetVal(next, day, emp, to, { start: spanStart, end: spanEnd, mixMaxRatio })) {
            return true;
          }
        }
      }
      return false;
    };

    let guard = days.length * 8;
    let cur = count();
    while (cur.mid > targetMid && guard-- > 0) {
      if (cur.white < targetWhite) {
        if (!tryConvert('中1', '白')) break;
      } else if (!tryConvert('中1', '休')) {
        break;
      }
      cur = count();
    }

    guard = days.length * 8;
    while (cur.mid < targetMid && guard-- > 0) {
      if (!tryConvert('白', '中1')) {
        if (!tryConvert('休', '中1')) break;
      }
      cur = count();
    }

    guard = days.length * 8;
    while (cur.white > targetWhite && guard-- > 0) {
      if (!tryConvert('白', '休')) break;
      cur = count();
    }

    guard = days.length * 8;
    while (cur.white < targetWhite && guard-- > 0) {
      if (!tryConvert('休', '白')) {
        if (!tryConvert('中1', '白')) break;
      }
      cur = count();
    }

    guard = days.length * 8;
    while (cur.total > targetTotal && guard-- > 0) {
      if (!tryConvert('白', '休')) {
        if (!tryConvert('中1', '休')) break;
      }
      cur = count();
    }

    guard = days.length * 8;
    while (cur.total < targetTotal && guard-- > 0) {
      if (cur.white < targetWhite) {
        if (!tryConvert('休', '白')) {
          if (!tryConvert('中1', '白')) break;
        }
      } else {
        if (!tryConvert('休', '中1') && !tryConvert('白', '中1')) break;
      }
      cur = count();
    }
  }

  function adjustWithHistory({ data, employees, start, end, adminDays, historyProfile, mixMaxRatio = 1, yearlyOptimize = false }) {
    if (!employees.length) return data;
    const days = enumerateDates(start, end);
    if (!days.length) return data;
    const next = JSON.parse(JSON.stringify(data));
    const totals = historyProfile?.shiftTotals || historyProfile?.shift_totals || {};
    const lastAssignments = historyProfile?.lastAssignments || historyProfile?.last_assignments || {};
    const spanStart = days[0];
    const spanEnd = days[days.length - 1];

    if (lastAssignments && spanStart) {
      employees.forEach(emp => {
        const prev = lastAssignments[emp];
        if (!prev) return;
        if ((prev === '夜' || prev === '中2') && isWork(getVal(next, spanStart, emp))) {
          setVal(next, spanStart, emp, '休');
        }
        if (prev === '中1' && getVal(next, spanStart, emp) === '白') {
          setVal(next, spanStart, emp, '休');
        }
      });
      if (yearlyOptimize) {
        employees.forEach(emp => {
          const prev = lastAssignments[emp];
          if (prev === '休' && isWork(getVal(next, spanStart, emp))) {
            setVal(next, spanStart, emp, '休');
          }
        });
      }
    }

    employees.forEach(emp => {
      const stats = statsForEmployee(next, emp, start, end);
      const totalDays = days.length;
      const targetTotal = Number.isFinite(adminDays) && adminDays > 0 ? Math.min(adminDays, totalDays) : stats.total;
      const hist = totals[emp] || {};
      const histTotal =
        hist.total ??
        hist.总 ??
        ((hist.white || hist.白 || 0) + (hist.mid || hist.中1 || 0) + (hist.mid2 || hist.中2 || 0) + (hist.night || hist.夜 || 0));
      let whiteShare = histTotal > 0 ? (hist.white ?? hist.白 ?? 0) / histTotal : stats.total ? stats.white / Math.max(1, stats.total) : 0.6;
      let midShare = histTotal > 0 ? (hist.mid ?? hist.中1 ?? 0) / histTotal : stats.total ? stats.mid / Math.max(1, stats.total) : 0.4;
      if (!Number.isFinite(whiteShare) || whiteShare < 0) whiteShare = 0;
      if (!Number.isFinite(midShare) || midShare < 0) midShare = 0;
      if (whiteShare + midShare > 1) {
        const sum = whiteShare + midShare;
        whiteShare /= sum;
        midShare /= sum;
      }
      let targetWhite = Math.round(targetTotal * whiteShare);
      let targetMid = Math.round(targetTotal * midShare);
      if (targetWhite + targetMid > targetTotal) {
        const overflow = targetWhite + targetMid - targetTotal;
        if (targetMid >= overflow) {
          targetMid -= overflow;
        } else {
          targetWhite = Math.max(0, targetWhite - (overflow - targetMid));
          targetMid = 0;
        }
      }
      adjustEmployeeSchedule({ next, emp, days, spanStart, spanEnd, targetWhite, targetMid, targetTotal, mixMaxRatio });
    });

    return next;
  }

  function normalizeNightRules(raw) {
    const defaults = {
      prioritizeInterval: false,
      restAfterNight: true,
      enforceRestCap: true,
      restAfterMid2: true,
      allowDoubleMid2: false,
      allowNightDay4: false,
    };
    if (!raw || typeof raw !== 'object') return { ...defaults };
    const next = { ...defaults };
    if (typeof raw.prioritizeInterval === 'boolean') next.prioritizeInterval = raw.prioritizeInterval;
    if (typeof raw.restAfterNight === 'boolean') next.restAfterNight = raw.restAfterNight;
    if (typeof raw.enforceRestCap === 'boolean') next.enforceRestCap = raw.enforceRestCap;
    if (typeof raw.restAfterMid2 === 'boolean') next.restAfterMid2 = raw.restAfterMid2;
    if (typeof raw.allowDoubleMid2 === 'boolean') next.allowDoubleMid2 = raw.allowDoubleMid2;
    if (typeof raw.allowNightDay4 === 'boolean') next.allowNightDay4 = raw.allowNightDay4;
    if (raw.rest2AfterNight && typeof raw.rest2AfterNight === 'object') {
      if (typeof raw.rest2AfterNight.enabled === 'boolean') next.restAfterNight = raw.rest2AfterNight.enabled;
      if (typeof raw.rest2AfterNight.mandatory === 'boolean') next.enforceRestCap = raw.rest2AfterNight.mandatory;
    }
    return next;
  }

  function autoAssignNightAndM2({ employees, data, start, end, override = false, nightRules, restPrefs = {} }) {
    const rules = normalizeNightRules(nightRules);
    const dates = enumerateDates(start, end);
    const newData = { ...data };
    const status = {};
    for (const e of employees) {
      status[e] = { lastNight: null, lastMid2: null };
    }

    function gapFrom(lastDay, curDay) {
      if (!lastDay) return Number.POSITIVE_INFINITY;
      return (new Date(curDay) - new Date(lastDay)) / 86400000;
    }

    function shouldRestFromHistory(emp, idx, kind) {
      if (idx === undefined || idx === null) return false;
      const nightRestEnforced = rules.restAfterNight || rules.allowNightDay4;
      if (!nightRestEnforced && !rules.restAfterMid2) return false;
      for (let back = 1; back <= 2; back++) {
        const prevIdx = idx - back;
        if (prevIdx < 0) continue;
        const prevDay = dates[prevIdx];
        const prevVal = getVal(newData, prevDay, emp);
        if (nightRestEnforced && prevVal === '夜') return true;
        if (rules.restAfterMid2 && prevVal === '中2') {
          if (kind === '中2' && rules.allowDoubleMid2 && back === 1) continue;
          return true;
        }
      }
      if (kind === '中2' && rules.allowDoubleMid2 && rules.restAfterMid2) {
        const prevDay = dates[idx - 1];
        const prevPrevDay = dates[idx - 2];
        if (prevDay && getVal(newData, prevDay, emp) === '中2' && prevPrevDay && getVal(newData, prevPrevDay, emp) === '中2') {
          return true;
        }
      }
      return false;
    }

    function ensureRest(emp, originIdx, daysCount, force = false) {
      for (let offset = 1; offset <= daysCount; offset++) {
        const targetIdx = originIdx + offset;
        if (targetIdx >= dates.length) break;
        const day = dates[targetIdx];
        const row = { ...(newData[day] || {}) };
        const cur = row[emp] || '';
        if (force || override || cur === '' || cur === '休') {
          row[emp] = '休';
          newData[day] = row;
        }
      }
    }

    function capRest(emp, originIdx) {
      if (!rules.enforceRestCap) return;
      const targetIdx = originIdx + 3;
      if (targetIdx >= dates.length) return;
      const day = dates[targetIdx];
      const row = { ...(newData[day] || {}) };
      if (row[emp] === '休') {
        const prevIdx = targetIdx - 1;
        const prevDay = prevIdx >= 0 ? dates[prevIdx] : null;
        const prevVal = prevDay ? getVal(newData, prevDay, emp) : '';
        row[emp] = prevVal === '白' ? '中1' : '白';
        newData[day] = row;
      }
    }

    function enforceNightDay4(emp, originIdx) {
      if (!rules.allowNightDay4) return;
      if (originIdx % 7 !== 3) return;
      ensureRest(emp, originIdx, 2, true);
      const day7Idx = originIdx + 3;
      if (day7Idx < dates.length) {
        const day7 = dates[day7Idx];
        const row = { ...(newData[day7] || {}) };
        if (row[emp] === '休' || !row[emp]) {
          row[emp] = '白';
          newData[day7] = row;
        }
      }
    }

    for (let i = 0; i < dates.length; i++) {
      const day = dates[i];
      const baseRow = { ...(newData[day] || {}) };
      if (!Object.values(baseRow).includes('夜')) {
        let candidates = employees.slice();
        if (rules.prioritizeInterval) {
          candidates.sort((a, b) => gapFrom(status[b].lastNight, day) - gapFrom(status[a].lastNight, day));
        } else {
          candidates.reverse();
        }
        const pickNight = candidates.find(emp => {
          const cur = baseRow[emp] || '';
          if (cur === '休' || cur === '中2') return false;
          if (!override && cur && cur !== '夜') return false;
          if (shouldRestFromHistory(emp, i, '夜')) return false;
          if (restPrefs && isRestDayForEmp(restPrefs, emp, day)) return false;
          return true;
        });
        if (pickNight) {
          baseRow[pickNight] = '夜';
          newData[day] = baseRow;
          status[pickNight].lastNight = day;
        } else {
          newData[day] = baseRow;
        }
      } else {
        newData[day] = baseRow;
      }

      const rowAfterNight = { ...(newData[day] || {}) };
      if (!Object.values(rowAfterNight).includes('中2')) {
        let midCandidates = employees.slice().reverse();
        const pickMid = midCandidates.find(emp => {
          const cur = rowAfterNight[emp] || '';
          if (cur === '休' || cur === '夜') return false;
          if (!override && cur && cur !== '中2') return false;
          if (shouldRestFromHistory(emp, i, '中2')) return false;
          if (!rules.allowDoubleMid2) {
            const prevDay = dates[i - 1];
            if (prevDay && getVal(newData, prevDay, emp) === '中2') return false;
          }
          if (restPrefs && isRestDayForEmp(restPrefs, emp, day)) return false;
          return true;
        });
        if (pickMid) {
          rowAfterNight[pickMid] = '中2';
          newData[day] = rowAfterNight;
          status[pickMid].lastMid2 = day;
        } else {
          newData[day] = rowAfterNight;
        }
      } else {
        newData[day] = rowAfterNight;
      }

      for (const emp of employees) {
        const val = getVal(newData, day, emp);
        const st = status[emp];
        if (val === '夜') st.lastNight = day;
        if (val === '中2') st.lastMid2 = day;
      }
    }

    if (rules.restAfterNight || rules.restAfterMid2) {
      for (const emp of employees) {
        for (let i = 0; i < dates.length; i++) {
          const day = dates[i];
          const val = getVal(newData, day, emp);
          if (val === '夜') {
            if (rules.restAfterNight) {
              ensureRest(emp, i, 2, true);
              capRest(emp, i);
            }
            enforceNightDay4(emp, i);
          }
          if (val === '中2' && rules.restAfterMid2) {
            const nextDay = dates[i + 1];
            if (rules.allowDoubleMid2 && nextDay && getVal(newData, nextDay, emp) === '中2') continue;
            ensureRest(emp, i, 2, true);
            capRest(emp, i);
          }
        }
      }
    }

    for (const e of employees) {
      let run = 0;
      for (const day of dates) {
        const v = getVal(newData, day, e);
        if (isWork(v)) {
          run++;
          if (run > 6) {
            const row = { ...(newData[day] || {}) };
            if (row[e] !== '夜' && row[e] !== '中2') {
              row[e] = '休';
              newData[day] = row;
              run = 0;
            }
          }
        } else {
          run = 0;
        }
      }
    }

    return newData;
  }

  return {
    SHIFT_TYPES,
    SHIFT_WORK_TYPES,
    REST_PAIRS,
    fmt,
    enumerateDates,
    dateAdd,
    isWork,
    getVal,
    setVal,
    countByEmpInRange,
    countRunLeft,
    countRunRight,
    wouldExceed6,
    normalizeRestPair,
    sanitizeRestPairValue,
    pairToSet,
    dayToW,
    isRestDayForEmp,
    buildWhiteFiveTwo,
    buildWorkBlocks,
    cyclesForEmp,
    isRightEdgeWhite,
    isLeftEdgeMid,
    dailyMidCount,
    dailyWhiteCount,
    empMidCountInRange,
    empWhiteCountInRange,
    mixedCyclesCount,
    longestRun,
    trySetVal,
    repairNoMidToWhite,
    applyAlternateByCycle,
    clampDailyByRange,
    clampPersonByRange,
    statsForEmployee,
    sortedByHistory,
    adjustEmployeeSchedule,
    adjustWithHistory,
    normalizeNightRules,
    autoAssignNightAndM2,
  };
})();

if (typeof window !== 'undefined') {
  window.AutoScheduler = AutoScheduler;
}
