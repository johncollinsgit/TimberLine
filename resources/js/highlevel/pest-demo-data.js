// Fixed fictional examples only. This module never reads CRM or provider data.
export const BRANCHES = ['Greenville', 'Spartanburg', 'Anderson', 'Greer'];
export const TERRITORIES = ['North', 'East', 'South', 'West'];
export const STORAGE_KEY = 'everbranch.fleet.pest-demo.v1';
export const DEFAULT_SETTINGS = { units: 'mi', staleMinutes: 15, defaultBranch: '', providerUnavailable: false };

export function createScenario() {
    const vehicles = Array.from({ length: 250 }, (_, i) => {
        const n = i + 1;
        return {
            id: n, name: `Truck ${String(n).padStart(3, '0')}`, branch: BRANCHES[i % 4],
            territory: TERRITORIES[Math.floor(i / 4) % 4], crew: `Team ${String(Math.floor(i / 5) + 1).padStart(2, '0')}`,
            technician: `Demo technician ${String(n).padStart(3, '0')}`,
            skills: n % 3 === 0 ? ['general pest', 'termite'] : ['general pest'],
            stock: { traps: n % 7 === 0 ? 0 : 12, 'bait stations': n % 3 === 0 ? 20 : 4 },
            capacity: n % 11 === 0 ? 0 : 3, available: n % 9 !== 0,
            outOfService: n % 47 === 0, disconnected: n % 37 === 0,
            ageMinutes: n % 19 === 0 ? 48 : n % 31 === 0 ? 23 : 2 + n % 6,
            x: 12 + ((n * 37) % 77), y: 14 + ((n * 23) % 66),
            odometer: 28000 + n * 347,
        };
    });
    const jobs = vehicles.map((v, i) => ({
        id: i + 1, title: ['Routine pest visit', 'Rodent follow-up', 'Termite inspection'][i % 3],
        customer: `Example customer ${String(i + 1).padStart(3, '0')}`, branch: v.branch, territory: v.territory,
        vehicleId: v.id, start: i % 17 === 0 ? 570 : 660, end: i % 17 === 0 ? 600 : 720,
        status: i % 13 === 0 ? 'completed' : 'scheduled', skills: ['general pest'],
        stock: { traps: 2 }, capacity: 1, x: 48, y: 44,
        note: 'Fictional visit. Check access instructions with the office before arrival.',
        previousVisit: 'Example routine visit 28 days ago; follow-up requested.',
    }));
    for (let i = 0; i < 8; i++) jobs.unshift({
        id: 1001 + i, title: i === 0 ? 'Urgent termite callback' : ['Unassigned routine visit', 'Rodent follow-up'][i % 2],
        customer: `Example callback customer ${i + 1}`, branch: BRANCHES[i % 4], territory: TERRITORIES[i % 4],
        vehicleId: null, start: i < 2 ? 600 : 630, end: i < 2 ? 660 : 690, status: 'scheduled',
        skills: i === 0 ? ['termite'] : ['general pest'], stock: i === 0 ? { 'bait stations': 8 } : { traps: 2 },
        capacity: 1, x: 51, y: 42, note: 'Fictional callback: office has confirmed a one-hour service window.',
        previousVisit: 'Example prior inspection: additional follow-up requested. No real treatment record.',
    });
    const maintenance = vehicles.filter(v => v.id % 29 === 0 || v.outOfService).map(v => ({
        id: v.id, vehicleId: v.id, title: v.outOfService ? 'Brake inspection' : 'Oil and filter change',
        due: v.outOfService ? 'Inspection required before return to service' : 'Mileage threshold reached',
        completed: false, notes: '',
    }));
    const alerts = vehicles.filter(v => v.id % 23 === 0).map(v => ({
        id: v.id, vehicleId: v.id, title: v.id % 2 ? 'Low battery' : 'Check-engine reading',
        status: 'open', notes: '',
    }));
    return { version: 1, vehicles, jobs, maintenance, alerts, reviews: {},
        settings: { ...DEFAULT_SETTINGS }, setup: [false, false, false, false, false], serviceHistory: [] };
}

export const isStale = (v, settings) => v.ageMinutes > settings.staleMinutes;
export function filteredVehicles(data, filters) {
    const q = filters.search.trim().toLowerCase();
    return data.vehicles.filter(v => (!filters.branch || v.branch === filters.branch)
        && (!filters.territory || v.territory === filters.territory) && (!filters.crew || v.crew === filters.crew)
        && (!q || `${v.name} ${v.technician} ${v.crew}`.toLowerCase().includes(q)));
}

export function attentionItems(data, vehicles = data.vehicles) {
    const ids = new Set(vehicles.map(v => v.id));
    const branchMatches = j => vehicles.some(v => v.branch === j.branch && v.territory === j.territory);
    const rows = [];
    for (const j of data.jobs.filter(j => j.status !== 'completed' && (j.vehicleId ? ids.has(j.vehicleId) : branchMatches(j)))) {
        if (!j.vehicleId) rows.push({ key: `unassigned-${j.id}`, kind: 'Unassigned', title: j.title, detail: `${j.customer} · ${j.branch}`, jobId: j.id });
        if (j.start < 610) rows.push({ key: `late-${j.id}`, kind: 'Start time passed', title: j.title, detail: 'Completion unconfirmed; check with dispatcher.', jobId: j.id });
    }
    for (const v of vehicles) {
        if (v.outOfService) rows.push({ key: `service-${v.id}`, kind: 'Out of service', title: v.name, detail: 'Manager-marked unavailable; inspection required.', vehicleId: v.id });
        if (v.disconnected) rows.push({ key: `offline-${v.id}`, kind: 'Device disconnected', title: v.name, detail: 'Fictional provider disconnect signal.', vehicleId: v.id });
        else if (isStale(v, data.settings)) rows.push({ key: `stale-${v.id}`, kind: 'Older location', title: v.name, detail: `${v.ageMinutes} minutes old; a parked truck can have an older reading.`, vehicleId: v.id });
    }
    return rows;
}

export function dispatchCandidates(data, job) {
    return data.vehicles.filter(v => v.branch === job.branch).map(v => {
        const reasons = [];
        if (v.outOfService) reasons.push('Out of service');
        if (v.disconnected || isStale(v, data.settings)) reasons.push('Recent location unavailable');
        if (!v.available) reasons.push('Availability unconfirmed');
        if (job.skills.some(s => !v.skills.includes(s))) reasons.push('Required skill missing');
        if (Object.entries(job.stock).some(([name, qty]) => (v.stock[name] || 0) < qty)) reasons.push('Required stock missing');
        if (!Number.isFinite(v.capacity) || v.capacity < job.capacity) reasons.push('Insufficient confirmed stop capacity');
        if (!Number.isFinite(job.start) || !Number.isFinite(job.end) || job.end <= job.start) reasons.push('Time window missing');
        if (data.jobs.some(j => j.id !== job.id && j.vehicleId === v.id && j.status !== 'completed'
            && (!Number.isFinite(j.start) || !Number.isFinite(j.end) || (j.start < job.end && j.end > job.start)))) reasons.push('Schedule conflict or unknown window');
        return { vehicle: v, reasons, miles: Math.round(Math.hypot(v.x - job.x, v.y - job.y) * 0.32 * 10) / 10 };
    }).sort((a, b) => a.miles - b.miles || a.vehicle.id - b.vehicle.id);
}

// Restore only the demo's own bounded, expected schema. Invalid state resets safely.
export function loadScenario(storage) {
    const fresh = createScenario();
    try {
        const raw = storage.getItem(STORAGE_KEY);
        if (!raw) return { data: fresh, recovered: false };
        const saved = JSON.parse(raw);
        if (saved.version !== 1 || !Array.isArray(saved.jobs) || saved.jobs.length !== fresh.jobs.length
            || !Array.isArray(saved.vehicles) || saved.vehicles.length !== 250
            || !Array.isArray(saved.setup) || saved.setup.length !== 5
            || !['mi', 'km'].includes(saved.settings?.units) || ![15, 30, 60].includes(saved.settings?.staleMinutes)
            || !['', ...BRANCHES].includes(saved.settings?.defaultBranch)) throw new Error('Invalid demo state');
        // No arbitrary stored records are admitted; apply only known IDs and permitted fields.
        fresh.settings = { ...saved.settings, providerUnavailable: saved.settings.providerUnavailable === true };
        fresh.setup = saved.setup.map(Boolean);
        for (const j of fresh.jobs) {
            const s = saved.jobs.find(x => x.id === j.id);
            if (s && (s.vehicleId === null || fresh.vehicles.some(v => v.id === s.vehicleId))) j.vehicleId = s.vehicleId;
        }
        for (const kind of ['maintenance', 'alerts']) for (const row of fresh[kind]) {
            const s = saved[kind]?.find(x => x.id === row.id);
            if (s) { row.completed = s.completed === true; row.status = s.status === 'resolved' ? 'resolved' : row.status; row.notes = String(s.notes || '').slice(0, 500); }
        }
        fresh.serviceHistory = fresh.maintenance.filter(m => m.completed).map(m => ({ vehicleId: m.vehicleId, title: m.title, notes: m.notes }));
        fresh.reviews = Object.fromEntries(Object.entries(saved.reviews || {}).filter(([k, v]) => /^\d{1,3}$/.test(k) && typeof v === 'string').map(([k, v]) => [k, v.slice(0, 500)]));
        return { data: fresh, recovered: false };
    } catch { return { data: fresh, recovered: true }; }
}
