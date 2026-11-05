import '../../css/stockists/locator.css';
import {textMatches, nearby} from './search';

const root = document.querySelector('[data-locator]');
if (root) {
    const payload = JSON.parse(document.getElementById('locator-data').textContent);
    const all = payload.locations, form = root.querySelector('[data-search]'), input = form.elements.q;
    const list = root.querySelector('#retailer-list'), status = root.querySelector('[role=status]');
    const mapStatus = root.querySelector('[data-map-status]');
    const cards = new Map([...list.querySelectorAll('[data-retailer]')].map(el => [Number(el.dataset.retailer), el]));
    let map, info, maps, pinLayer, geocoder, shown = all, request = 0;
    let mapReady;

    function select(item, scrollList = false) {
        for (const [id, card] of cards) { card.setAttribute('aria-current', String(id === item.id)); }
        const card = cards.get(item.id);
        if (scrollList) card?.scrollIntoView({block: 'nearest', behavior: 'auto'});
        if (!map) return;
        map.panTo({lat: item.latitude, lng: item.longitude}); map.setZoom(14);
        const box = document.createElement('div'); box.className = 'locator-popup';
        const title = document.createElement('strong'); title.textContent = item.name;
        const address = document.createElement('p'); address.textContent = item.address;
        const link = document.createElement('a'); link.textContent = 'Get directions ↗'; link.href = card.querySelector('a').href; link.target = '_blank'; link.rel = 'noopener';
        box.append(title, address, link); info.setContent(box); info.setPosition({lat:item.latitude,lng:item.longitude}); info.open({map, shouldFocus:false});
    }
    function updateMap() {
        if (!map) return;
        info.close();
        pinLayer?.draw();
        const bounds = new maps.LatLngBounds();
        for (const item of shown) bounds.extend({lat:item.latitude,lng:item.longitude});
        if (shown.length === 1) { map.setCenter(bounds.getCenter()); map.setZoom(14); }
        else if (shown.length > 1) map.fitBounds(bounds, 30);
    }
    function render(items, message) {
        shown = items;
        for (const card of cards.values()) { card.hidden = true; card.removeAttribute('aria-current'); }
        for (const item of items) {
            const card = cards.get(item.id); card.hidden = false; list.append(card);
            card.querySelector('[data-distance]').textContent = item.distance == null ? '' : `${item.distance.toFixed(1)} miles away`;
        }
        root.querySelector('[data-empty]').hidden = items.length > 0;
        status.textContent = message || `${items.length} ${items.length === 1 ? 'retailer' : 'retailers'} found`;
        updateMap();
    }
    function loadGoogle() {
        if (!payload.mapsKey) return Promise.reject(new Error('Maps key unavailable'));
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            const timer = setTimeout(() => reject(new Error('Maps timeout')), 15000);
            window.everbranchStockistMapReady = () => { clearTimeout(timer); resolve(window.google.maps); };
            window.gm_authFailure = () => reject(new Error('Maps authorization failed'));
            script.src = `https://maps.googleapis.com/maps/api/js?${new URLSearchParams({key:payload.mapsKey, v:'weekly', loading:'async', libraries:'geocoding', callback:'everbranchStockistMapReady'})}`;
            script.async = true; script.onerror = () => { clearTimeout(timer); reject(new Error('Maps unavailable')); }; document.head.append(script);
        });
    }
    mapReady = loadGoogle().then(async googleMaps => {
        maps = googleMaps;
        map = new maps.Map(document.getElementById('retailer-map'), {center:{lat:34.8,lng:-82.5},zoom:4,gestureHandling:'cooperative',streetViewControl:false,mapTypeControl:false});
        info = new maps.InfoWindow(); geocoder = new maps.Geocoder();
        // HTML button overlays keep markers keyboard accessible without a new map ID.
        pinLayer = new class extends maps.OverlayView {
            onAdd() { this.container = document.createElement('div'); this.getPanes().overlayMouseTarget.append(this.container); }
            draw() {
                if (!this.container || !this.getProjection()) return;
                const previous = document.activeElement?.dataset?.pin;
                this.container.replaceChildren(); const groups = new Map();
                for (const item of shown) {
                    const point = this.getProjection().fromLatLngToDivPixel(new maps.LatLng(item.latitude,item.longitude));
                    const key = `${Math.floor(point.x/52)}:${Math.floor(point.y/52)}`;
                    if(!groups.has(key)) groups.set(key,[]); groups.get(key).push({item,point});
                }
                for (const group of groups.values()) {
                    const {item,point} = group[0], button = document.createElement('button');
                    button.type='button'; button.className='locator-pin'; button.dataset.pin=String(item.id);
                    button.style.left=`${point.x}px`; button.style.top=`${point.y}px`;
                    const face=document.createElement('span'); face.textContent=group.length>1 ? String(group.length) : '●'; button.append(face);
                    button.setAttribute('aria-label',group.length>1 ? `${group.length} retailers. Zoom in to explore.` : `${item.name}, ${item.address}`);
                    maps.OverlayView.preventMapHitsAndGesturesFrom(button);
                    button.addEventListener('click', () => {
                        if(group.length===1) select(item,true);
                        else { const bounds=new maps.LatLngBounds(); group.forEach(({item})=>bounds.extend({lat:item.latitude,lng:item.longitude})); map.fitBounds(bounds,55); }
                    });
                    this.container.append(button);
                    if(previous===button.dataset.pin) button.focus({preventScroll:true});
                }
            }
            onRemove() { this.container?.remove(); }
        };
        pinLayer.setMap(map); mapStatus.hidden = true; updateMap(); return true;
    }).catch(() => { mapStatus.textContent = 'The map is unavailable. Browse all retailers and open directions from the list.'; return false; });
    form.addEventListener('submit', async event => {
        event.preventDefault(); const sequence = ++request, query = input.value.trim();
        if (!query) { render(all); return; }
        const matches = textMatches(all, query);
        if (matches.length) { render(matches); return; }
        const submit = form.querySelector('[type=submit]'); submit.disabled = true; status.textContent = 'Looking up this address…';
        try {
            const ready = await mapReady;
            if (!ready) { if(sequence===request) render(all, 'Address lookup is unavailable. Browse the list or search by retailer, city or ZIP shown there.'); return; }
            const result = await geocoder.geocode({address:query});
            if (sequence !== request) return;
            const point = result.results?.[0]?.geometry?.location;
            if (!point) throw new Error('Address not found. Try a nearby city or ZIP code.');
            const items = nearby(all, {latitude:point.lat(),longitude:point.lng()});
            render(items, `${items.length} retailers within 50 miles of ${result.results[0].formatted_address}`);
        } catch { if (sequence === request) render([], 'Address not found. Try another city or ZIP code, or choose Show all.'); }
        finally { submit.disabled = false; }
    });
    root.querySelector('[data-reset]').addEventListener('click', () => { ++request; input.value = ''; render(all); input.focus(); });
    root.querySelector('[data-nearby]').addEventListener('click', () => {
        const sequence = ++request; status.textContent = 'Waiting for your location permission…';
        if (!navigator.geolocation) { status.textContent = 'Location is unavailable. Search by city, ZIP code or store name.'; return; }
        navigator.geolocation.getCurrentPosition(position => {
            if (sequence !== request) return;
            const items = nearby(all, {latitude:position.coords.latitude,longitude:position.coords.longitude});
            render(items, `${items.length} retailers within 50 miles of your location`);
        }, () => { if(sequence === request) status.textContent = 'Location access was unavailable. Search by city, ZIP code or store name.'; }, {timeout:10000,maximumAge:60000,enableHighAccuracy:false});
    });
    list.addEventListener('click', event => { const button = event.target.closest('[data-select]'); if(button) select(all.find(item => item.id === Number(button.dataset.select))); });
}
