export function normalize(value) { return String(value).normalize('NFKD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim(); }
export function textMatches(locations, query) {
    const words = normalize(query).split(' ').filter(Boolean);
    return locations.filter(item => words.every(word => normalize(`${item.name} ${item.address}`).includes(word)));
}
export function milesBetween(a, b) {
    const rad = value => value * Math.PI / 180;
    const dLat = rad(b.latitude - a.latitude), dLng = rad(b.longitude - a.longitude);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLng / 2) ** 2;
    return 3958.7613 * 2 * Math.asin(Math.min(1, Math.sqrt(h)));
}
export function nearby(locations, origin, radius = 50) {
    return locations.map(item => ({...item, distance: milesBetween(origin, item)})).filter(item => item.distance <= radius).sort((a, b) => a.distance - b.distance);
}
