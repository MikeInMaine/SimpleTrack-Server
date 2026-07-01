<?php require_once __DIR__ . '/auth.php'; auth_check(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LocTrack</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #1a1a2e; color: #e0e0e0;
            height: 100vh; display: flex; flex-direction: column;
        }
        #header {
            background: #16213e; padding: 10px 16px;
            display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #0f3460; flex-shrink: 0;
        }
        #header h1 { font-size: 18px; color: #4fc3f7; letter-spacing: 1px; }
        #last-update { font-size: 12px; color: #888; }
        #sidebar {
            display: flex; flex-direction: row; flex-wrap: wrap;
            gap: 8px; padding: 10px 16px; background: #16213e;
            border-bottom: 1px solid #0f3460; flex-shrink: 0;
        }
        .device-card {
            background: #0f3460; border-radius: 8px; padding: 8px 12px;
            min-width: 160px; cursor: pointer; border: 2px solid transparent;
            transition: border-color 0.2s; border-left-width: 4px;
        }
        .device-card:hover { filter: brightness(1.15); }
        .device-card.active { border-color: #4fc3f7; }
        .device-name { font-weight: bold; font-size: 14px; display: flex; align-items: center; gap: 6px; }
        .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
        .status-dot.online  { background: #4caf50; }
        .status-dot.offline { background: #f44336; }
        .device-detail  { font-size: 11px; color: #aaa; margin-top: 2px; }
        .device-speed   { font-size: 13px; color: #4fc3f7; margin-top: 2px; font-weight: 500; }
        .device-address { font-size: 11px; color: #90caf9; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
        .device-battery { font-size: 11px; margin-top: 3px; }
        #map-wrap { position: relative; flex: 1; display: flex; flex-direction: column; }
        #map { flex: 1; }
        #info-panel {
            display: none; position: absolute; top: 10px; right: 10px; z-index: 1000;
            background: rgba(15,52,96,0.97); border: 1px solid #4fc3f7;
            border-radius: 10px; padding: 14px 16px; min-width: 240px; max-width: 280px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        }
        #stops-panel {
            display: none; position: absolute; top: 10px; left: 10px; z-index: 1000;
            background: rgba(15,52,96,0.97); border: 1px solid #4fc3f7;
            border-radius: 10px; padding: 14px 16px; min-width: 260px; max-width: 300px;
            max-height: 70vh; overflow-y: auto; box-shadow: 0 4px 20px rgba(0,0,0,0.5);
        }
        #stops-panel h3 { font-size: 14px; color: #4fc3f7; margin-bottom: 8px; }
        .panel-close {
            position: absolute; top: 8px; right: 10px;
            cursor: pointer; color: #888; font-size: 16px; line-height: 1;
        }
        .panel-close:hover { color: #fff; }
        .info-row { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 6px; gap: 8px; }
        .info-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; flex-shrink: 0; padding-top: 1px; }
        .info-value { font-size: 13px; color: #e0e0e0; text-align: right; }
        .info-value.highlight { color: #4fc3f7; font-weight: 600; }
        #info-name { font-size: 16px; font-weight: bold; margin-bottom: 10px; padding-right: 20px; }
        #info-address { font-size: 12px; color: #90caf9; margin-top: 8px; padding-top: 8px; border-top: 1px solid #1a4a80; line-height: 1.4; min-height: 18px; }
        #info-address.loading { color: #666; font-style: italic; }
        .stop-item { border-bottom: 1px solid #1a4a80; padding: 8px 0; font-size: 12px; }
        .stop-item:last-child { border-bottom: none; }
        .stop-device { font-weight: bold; font-size: 13px; margin-bottom: 3px; }
        .stop-time   { color: #aaa; margin-bottom: 2px; }
        .stop-dur    { color: #4fc3f7; }
        .stop-addr   { color: #90caf9; margin-top: 3px; line-height: 1.3; }
        .stop-open   { color: #4caf50; font-style: italic; }
        #controls {
            background: #16213e; padding: 8px 16px;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            border-top: 1px solid #0f3460; flex-shrink: 0;
        }
        #controls label { font-size: 13px; color: #aaa; }
        #controls select, #controls button {
            background: #0f3460; color: #e0e0e0; border: 1px solid #4fc3f7;
            border-radius: 4px; padding: 4px 10px; font-size: 13px; cursor: pointer;
        }
        #controls button:hover { background: #1a4a80; }
        #no-data {
            display: none; position: absolute; top: 50%; left: 50%;
            transform: translate(-50%,-50%); background: rgba(22,33,62,0.92);
            padding: 20px 30px; border-radius: 10px; text-align: center;
            z-index: 1000; font-size: 15px; color: #aaa;
        }
        .stops-filter {
            width: 100%; background: #0f3460; color: #e0e0e0;
            border: 1px solid #4fc3f7; border-radius: 4px;
            padding: 4px 8px; font-size: 12px; margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <h1>&#9679; LocTrack</h1>
    <span id="last-update">Loading...</span>
</div>
<div id="sidebar"></div>
<div id="map-wrap">
    <div id="map"></div>
    <div id="info-panel">
        <span class="panel-close" onclick="closeInfoPanel()">&#x2715;</span>
        <div id="info-name"></div>
        <div class="info-row"><span class="info-label">Status</span><span class="info-value" id="info-status"></span></div>
        <div class="info-row"><span class="info-label">Activity</span><span class="info-value" id="info-activity"></span></div>
        <div class="info-row"><span class="info-label">Speed</span><span class="info-value highlight" id="info-speed"></span></div>
        <div class="info-row"><span class="info-label">Direction</span><span class="info-value" id="info-direction"></span></div>
        <div class="info-row"><span class="info-label">Altitude</span><span class="info-value" id="info-altitude"></span></div>
        <div class="info-row"><span class="info-label">Accuracy</span><span class="info-value" id="info-accuracy"></span></div>
        <div class="info-row"><span class="info-label">Battery</span><span class="info-value" id="info-battery"></span></div>
        <div class="info-row"><span class="info-label">Last Seen</span><span class="info-value" id="info-lastseen"></span></div>
        <div class="info-row"><span class="info-label">Coords</span><span class="info-value" id="info-coords" style="font-size:11px;"></span></div>
        <div id="info-address" class="loading">Looking up address...</div>
    </div>
    <div id="stops-panel">
        <span class="panel-close" onclick="toggleStopsPanel()">&#x2715;</span>
        <h3>&#128205; Stop History</h3>
        <select id="stops-device" class="stops-filter" onchange="loadStops()">
            <option value="">All devices</option>
        </select>
        <div id="stops-list">Loading...</div>
    </div>
    <div id="no-data">No location data yet.<br>Waiting for devices to check in.</div>
</div>
<div id="controls">
    <label for="history-hours">History:</label>
    <select id="history-hours">
        <option value="0">Live only</option>
        <option value="1">1 hour</option>
        <option value="6">6 hours</option>
        <option value="24" selected>24 hours</option>
        <option value="72">3 days</option>
        <option value="168">1 week</option>
    </select>
    <button onclick="fitAll()">&#8982; Fit All</button>
    <button onclick="clearHistory()">Clear Trails</button>
    <button onclick="toggleStopsPanel()">&#128205; Stop History</button>
    <label for="stops-days">Stops:</label>
    <select id="stops-days" onchange="if(stopsVisible) loadStops()">
        <option value="1">Today</option>
        <option value="7" selected>7 days</option>
        <option value="30">30 days</option>
    </select>
</div>
<script>
const COLORS = { 'Michael': '#4fc3f7', 'Mary': '#f48fb1', 'Akiva': '#a5d6a7' };
const DEFAULT_COLOR = '#ffcc80';

const map = L.map('map', { center: [44.5, -69.0], zoom: 8 });
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap contributors', maxZoom: 19,
}).addTo(map);

let markers = {}, polylines = {}, deviceData = {}, activeDevice = null;
let stopsVisible = false, stopMarkers = [], geocodeCache = {};

function makeIcon(device, online) {
    const color = COLORS[device] || DEFAULT_COLOR;
    const opacity = online ? 1.0 : 0.4;
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="40" viewBox="0 0 32 40">
        <ellipse cx="16" cy="37" rx="6" ry="3" fill="rgba(0,0,0,0.3)"/>
        <path d="M16 2 C9 2 4 8 4 15 C4 24 16 38 16 38 C16 38 28 24 28 15 C28 8 23 2 16 2Z"
              fill="${color}" opacity="${opacity}" stroke="white" stroke-width="1.5"/>
        <circle cx="16" cy="15" r="5" fill="white" opacity="0.85"/>
    </svg>`;
    return L.divIcon({ html: svg, iconSize: [32,40], iconAnchor: [16,38], popupAnchor: [0,-38], className: '' });
}

function formatAgo(mins) {
    if (mins < 1)  return 'just now';
    if (mins < 60) return mins + 'm ago';
    const h = Math.floor(mins/60), m = mins%60;
    return h + 'h ' + (m > 0 ? m + 'm ' : '') + 'ago';
}

function bearingToCompass(deg) {
    if (deg < 0) return '—';
    return ['N','NE','E','SE','S','SW','W','NW'][Math.round(deg/45)%8];
}

function bearingArrow(deg) {
    if (deg < 0) return '';
    return ['↑','↗','→','↘','↓','↙','←','↖'][Math.round(deg/45)%8];
}

function formatDuration(mins) {
    if (mins === null) return '';
    if (mins < 60) return mins + ' min';
    const h = Math.floor(mins/60), m = mins%60;
    return h + 'h' + (m > 0 ? ' ' + m + 'm' : '');
}

function formatDateTime(dtStr) {
    if (!dtStr) return '';
    const d = new Date(dtStr + 'Z');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
        + ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}

function formatBattery(level, charging) {
    if (level === null || level === undefined) return '—';
    const pct = Math.round(level * 100);
    const icon = charging ? '⚡' : (pct > 80 ? '🔋' : pct > 20 ? '🔋' : '🪫');
    return icon + ' ' + pct + '%' + (charging ? ' charging' : '');
}

function batteryColor(level) {
    if (level === null) return '#aaa';
    const pct = level * 100;
    if (pct > 50) return '#4caf50';
    if (pct > 20) return '#ff9800';
    return '#f44336';
}

function formatActivity(activity) {
    if (!activity) return '—';
    const icons = {
        'still':      '&#9679; Still',
        'walking':    '&#x1F6B6; Walking',
        'running':    '&#x1F3C3; Running',
        'on_bicycle': '&#x1F6B4; Cycling',
        'in_vehicle': '&#x1F697; In vehicle',
        'unknown':    '— Unknown',
    };
    return icons[activity] || activity;
}

async function getAddress(lat, lon) {
    const key = lat.toFixed(4) + ',' + lon.toFixed(4);
    if (geocodeCache[key]) return geocodeCache[key];
    try {
        // Always use zoom=16 (street level) — let Nominatim tell us if it's water
        const resp = await fetch(
            `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lon}&format=json&zoom=16`,
            { headers: { 'Accept-Language': 'en' } }
        );
        const data = await resp.json();
        const a = data.address || {};

        // Detect water via OSM class/type and address fields
        const waterTypes = ['water', 'bay', 'sea', 'ocean', 'strait', 'harbour',
                            'inlet', 'cove', 'lake', 'reservoir', 'river', 'stream'];
        const isWaterResult = (data.class === 'natural' && data.type === 'water')
            || (data.class === 'waterway')
            || waterTypes.some(t => a[t] !== undefined)
            || waterTypes.some(t => (data.type || '').toLowerCase() === t);
        if (isWaterResult) {
            const waterName = a.water || a.bay || a.sea || a.ocean || a.strait
                           || a.harbour || a.inlet || a.cove || a.lake || a.river
                           || data.name || null;
            geocodeCache[key] = '\u{1F30A} ' + (waterName ? waterName : 'On the water');
            return geocodeCache[key];
        }

        const parts = [];
        if (a.house_number && a.road) parts.push(a.house_number + ' ' + a.road);
        else if (a.road)              parts.push(a.road);
        else if (a.hamlet)            parts.push(a.hamlet);
        const place = a.city || a.town || a.village || a.municipality;
        if (place)   parts.push(place);
        if (a.state) parts.push(a.state);
        const address = parts.length ? parts.join(', ') : (data.display_name || 'Unknown location');
        geocodeCache[key] = address;
        return address;
    } catch(e) { return 'Address unavailable'; }
}

function updateSidebar(devices) {
    const sidebar = document.getElementById('sidebar');
    sidebar.innerHTML = '';
    if (!devices || devices.length === 0) return;
    devices.forEach(d => {
        const color  = COLORS[d.device] || DEFAULT_COLOR;
        const card   = document.createElement('div');
        card.className = 'device-card' + (activeDevice === d.device ? ' active' : '');
        card.dataset.device = d.device;
        card.style.borderLeftColor = color;
        const moving  = d.speed_mph > 0.5;
        const batPct  = d.battery_level !== null ? Math.round(d.battery_level * 100) : null;
        const batStr  = batPct !== null
            ? `<span style="color:${batteryColor(d.battery_level)}">${d.is_charging ? '⚡' : '🔋'} ${batPct}%</span>`
            : '';
        const actStr  = d.activity && d.activity !== 'still' && d.activity !== 'unknown'
            ? `<span style="color:#aaa;font-size:11px;"> &middot; ${formatActivity(d.activity)}</span>`
            : '';
        card.innerHTML = `
            <div class="device-name">
                <span class="status-dot ${d.online ? 'online' : 'offline'}"></span>
                <span style="color:${color}">${d.device}</span>
            </div>
            <div class="device-speed">${moving ? bearingArrow(d.bearing)+' '+d.speed_mph+' mph '+bearingToCompass(d.bearing) : '&#9679; Stopped'}${actStr}</div>
            <div class="device-detail">${formatAgo(d.mins_ago)}</div>
            <div class="device-battery">${batStr}</div>
            <div class="device-address" id="addr-${d.device}">&#8987; locating...</div>
        `;
        card.addEventListener('click', () => focusDevice(d.device));
        sidebar.appendChild(card);
        getAddress(d.lat, d.lon).then(addr => {
            const el = document.getElementById('addr-' + d.device);
            if (el) el.textContent = addr;
        });
    });
}

function updateMarker(d) {
    const color  = COLORS[d.device] || DEFAULT_COLOR;
    const moving = d.speed_mph > 0.5;
    const popup  = `<b style="color:${color}">${d.device}</b><br>
        ${moving ? bearingArrow(d.bearing)+' '+d.speed_mph+' mph '+bearingToCompass(d.bearing) : 'Stopped'}<br>
        ${formatBattery(d.battery_level, d.is_charging)}<br>
        <small>${formatAgo(d.mins_ago)}</small>`;
    if (markers[d.device]) {
        markers[d.device].setLatLng([d.lat, d.lon]);
        markers[d.device].setIcon(makeIcon(d.device, d.online));
        markers[d.device].getPopup().setContent(popup);
    } else {
        markers[d.device] = L.marker([d.lat, d.lon], { icon: makeIcon(d.device, d.online) })
            .bindPopup(popup).addTo(map);
        markers[d.device].on('click', () => showInfoPanel(d.device));
    }
}

function showInfoPanel(device) {
    const d = deviceData[device];
    if (!d) return;
    activeDevice = device;
    const color = COLORS[device] || DEFAULT_COLOR;
    document.getElementById('info-name').innerHTML    = `<span style="color:${color}">&#9679;</span> ${device}`;
    document.getElementById('info-status').textContent   = d.online ? '🟢 Online' : '🔴 Offline';
    document.getElementById('info-activity').innerHTML   = formatActivity(d.activity);
    document.getElementById('info-speed').textContent    = d.speed_mph > 0.5 ? d.speed_mph + ' mph' : 'Stopped';
    document.getElementById('info-direction').textContent= d.bearing >= 0
        ? bearingArrow(d.bearing)+' '+bearingToCompass(d.bearing)+' ('+Math.round(d.bearing)+'°)' : '—';
    document.getElementById('info-altitude').textContent = d.altitude_ft ? Math.round(d.altitude_ft) + ' ft' : '—';
    document.getElementById('info-accuracy').textContent = d.accuracy_ft ? '±'+Math.round(d.accuracy_ft)+' ft' : '—';
    document.getElementById('info-battery').innerHTML    = formatBattery(d.battery_level, d.is_charging);
    document.getElementById('info-lastseen').textContent = formatAgo(d.mins_ago);
    document.getElementById('info-coords').textContent   = d.lat.toFixed(5)+', '+d.lon.toFixed(5);
    const addrEl = document.getElementById('info-address');
    addrEl.className = 'loading'; addrEl.textContent = 'Looking up address...';
    document.getElementById('info-panel').style.display = 'block';
    getAddress(d.lat, d.lon).then(addr => { addrEl.className = ''; addrEl.textContent = '📍 ' + addr; });
    document.querySelectorAll('.device-card').forEach(c => c.classList.toggle('active', c.dataset.device === device));
}

function closeInfoPanel() {
    document.getElementById('info-panel').style.display = 'none';
    activeDevice = null;
    document.querySelectorAll('.device-card').forEach(c => c.classList.remove('active'));
}

function toggleStopsPanel() {
    stopsVisible = !stopsVisible;
    document.getElementById('stops-panel').style.display = stopsVisible ? 'block' : 'none';
    if (stopsVisible) loadStops(); else clearStopMarkers();
}

function clearStopMarkers() {
    stopMarkers.forEach(m => map.removeLayer(m));
    stopMarkers = [];
}

async function populateDeviceFilter() {
    try {
        const resp    = await fetch('api.php?action=devices');
        const devices = await resp.json();
        const sel     = document.getElementById('stops-device');
        devices.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d; opt.textContent = d;
            sel.appendChild(opt);
        });
    } catch(e) { console.warn('Could not load device list', e); }
}

async function loadStops() {
    const days   = document.getElementById('stops-days').value;
    const device = document.getElementById('stops-device').value;
    const list   = document.getElementById('stops-list');
    list.textContent = 'Loading...';
    clearStopMarkers();
    try {
        const url   = 'api.php?action=stops&days=' + days + (device ? '&device=' + encodeURIComponent(device) : '');
        const resp  = await fetch(url);
        const stops = await resp.json();
        if (!stops || stops.length === 0) {
            list.innerHTML = '<div style="color:#888;font-size:12px;">No stops recorded yet.</div>';
            return;
        }
        list.innerHTML = '';
        for (const s of stops) {
            const color = COLORS[s.device] || DEFAULT_COLOR;
            const div   = document.createElement('div');
            div.className = 'stop-item';
            div.innerHTML = `
                <div class="stop-device" style="color:${color}">&#9679; ${s.device}</div>
                <div class="stop-time">${formatDateTime(s.arrived_at)}</div>
                <div class="stop-dur">${s.open
                    ? '<span class="stop-open">&#9679; Currently here</span>'
                    : (s.duration_min !== null ? '&#x23F1; '+formatDuration(s.duration_min) : '')}</div>
                <div class="stop-addr" id="saddr-${s.id}">${s.address || '&#8987; locating...'}</div>
            `;
            list.appendChild(div);
            if (!s.address) {
                getAddress(s.lat, s.lon).then(a => {
                    const el = document.getElementById('saddr-' + s.id);
                    if (el) el.textContent = a;
                });
            }
            const marker = L.circleMarker([s.lat, s.lon], {
                radius: s.open ? 9 : 6, color, fillColor: color,
                fillOpacity: s.open ? 0.8 : 0.35, weight: 2,
            }).addTo(map);
            marker.bindPopup(`<b style="color:${color}">${s.device}</b><br>
                ${formatDateTime(s.arrived_at)}<br>
                ${s.open ? '<i>Currently here</i>' : formatDuration(s.duration_min)}`);
            stopMarkers.push(marker);
        }
    } catch(e) {
        list.innerHTML = '<div style="color:#f44;">Failed to load stops.</div>';
    }
}

async function fetchLatest() {
    try {
        const resp = await fetch('api.php?action=latest');
        const data = await resp.json();
        document.getElementById('last-update').textContent = 'Updated ' + new Date().toLocaleTimeString();
        if (!data || data.length === 0) {
            document.getElementById('no-data').style.display = 'block'; return;
        }
        document.getElementById('no-data').style.display = 'none';
        data.forEach(d => { deviceData[d.device] = d; updateMarker(d); });
        updateSidebar(data);
        if (activeDevice && deviceData[activeDevice]) showInfoPanel(activeDevice);
    } catch(e) {
        document.getElementById('last-update').textContent = 'Update failed';
    }
}

async function fetchHistory(device) {
    const hours = parseInt(document.getElementById('history-hours').value);
    if (hours === 0) return;
    if (polylines[device]) { map.removeLayer(polylines[device]); delete polylines[device]; }
    try {
        const resp   = await fetch(`api.php?action=history&device=${encodeURIComponent(device)}&hours=${hours}`);
        const points = await resp.json();
        if (!points || points.length < 2) return;
        const color   = COLORS[device] || DEFAULT_COLOR;
        polylines[device] = L.polyline(points.map(p => [p.lat, p.lon]),
            { color, weight: 3, opacity: 0.6 }).addTo(map);
    } catch(e) { console.warn('History fetch failed', device, e); }
}

async function fetchAllHistory() {
    const hours = parseInt(document.getElementById('history-hours').value);
    if (hours === 0) { clearHistory(); return; }
    for (const device of Object.keys(deviceData)) await fetchHistory(device);
}

function clearHistory() {
    Object.keys(polylines).forEach(d => { map.removeLayer(polylines[d]); delete polylines[d]; });
}

function focusDevice(device) {
    if (markers[device]) { map.setView(markers[device].getLatLng(), 14); markers[device].openPopup(); }
    showInfoPanel(device);
}

function fitAll() {
    const ll = Object.values(markers).map(m => m.getLatLng());
    if (ll.length === 0) return;
    if (ll.length === 1) { map.setView(ll[0], 13); return; }
    map.fitBounds(L.latLngBounds(ll), { padding: [40,40] });
}

document.getElementById('history-hours').addEventListener('change', fetchAllHistory);
populateDeviceFilter();
let firstLoad = true;
fetchLatest().then(() => { if (firstLoad) { fitAll(); firstLoad = false; } fetchAllHistory(); });
setInterval(() => fetchLatest().then(fetchAllHistory), 30000);
</script>
</body>
</html>
