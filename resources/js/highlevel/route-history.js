import L from 'leaflet';
const colors=['#2563a6','#b64a35','#297954','#8353a5','#ac741f','#16818d','#a43870','#536231'];
const coordinate=p=>Array.isArray(p)&&p.length>=2&&Number.isFinite(p[0])&&Number.isFinite(p[1])&&Math.abs(p[0])<=90&&Math.abs(p[1])<=180;
export const routeColor=id=>colors[Math.abs(Number(id)||0)%colors.length];
export class RouteHistory {
    checked=new Set(); known=new Set(); records=new Map(); start=''; end=''; show=true; loading=false; pending=null; epoch=0; fitted=false; allowed=null; leafletLayers=[]; googleLayers=[];
    configure(data){
        const allowed=Boolean(data.demo || (data.subscription.collection_active && data.settings.policy_approved && data.settings.tracking_enabled));
        if(this.allowed!==null && this.allowed!==allowed){this.records.clear();this.epoch++;}
        this.allowed=allowed;
        const ids=new Set(data.vehicles.map(v=>v.id));
        for(const v of data.vehicles){if(!this.known.has(v.id)){this.checked.add(v.id);this.known.add(v.id);}}
        for(const id of this.checked){if(!ids.has(id))this.checked.delete(id);}
        this.start ||= data.route_range?.today || new Date().toISOString().slice(0,10);
        this.end ||= this.start;
    }
    reset(){this.checked.clear();this.known.clear();this.records.clear();this.epoch++;this.start='';this.end='';this.fitted=false;this.pending=null;}
    range(start,end){this.start=start;this.end=end;this.records.clear();this.epoch++;this.fitted=false;}
    status(id){
        if(!this.checked.has(id))return 'Hidden from map';
        if(!this.show)return 'Latest location only';
        const r=this.records.get(id);
        if(!r)return 'Route history queued';
        if(r.status==='loading')return 'Loading recorded routes…';
        if(r.status==='ready')return `${r.trips.length} ${r.demo?'example':'recorded'} trip${r.trips.length===1?'':'s'}${r.trips.some(t=>t.geometry_limited)?' · Incomplete geometry':''}`;
        return r.message || 'No recorded routes available';
    }
    async load(data,api,onChange){
        this.configure(data);
        if(!this.show)return;
        if(this.loading){this.pending={data,api,onChange};return;}
        this.loading=true;this.pending=null;
        const epoch=this.epoch,queue=data.vehicles.filter(v=>this.checked.has(v.id)&&!this.records.has(v.id));
        const worker=async()=>{
            while(queue.length && epoch===this.epoch){
                const vehicle=queue.shift();if(!this.checked.has(vehicle.id))continue;
                if(!this.allowed){this.records.set(vehicle.id,{status:'disabled',trips:[],samples:[],message:'Routes await pilot activation and approved collection.'});onChange();continue;}
                this.records.set(vehicle.id,{status:'loading',trips:[],samples:[]});onChange();
                try {
                    const result=await api(`operations/vehicles/${vehicle.id}/history?start=${encodeURIComponent(this.start)}&end=${encodeURIComponent(this.end)}`);
                    if(epoch===this.epoch)this.records.set(vehicle.id,result);
                } catch(error){if(epoch===this.epoch)this.records.set(vehicle.id,{status:'error',trips:[],samples:[],message:error.message||'Route history could not load. Try again.'});}
                if(epoch===this.epoch)onChange();
            }
        };
        try{await Promise.all(Array.from({length:Math.min(3,queue.length)},worker));}
        finally{this.loading=false;onChange();const next=this.pending;this.pending=null;if(next)void this.load(next.data,next.api,next.onChange);}
    }
    shapes(){
        if(!this.show || !this.allowed)return [];
        return [...this.checked].flatMap(id=>{
            const result=this.records.get(id);
            return (result?.trips||[]).flatMap(trip=>(trip.segments||[]).flatMap((segment,index)=>{
                const points=segment.filter(coordinate);
                return points.length?[{id,points,line:trip.trace_segments?.[index]===true && points.length>=2}]:[];
            })).concat((result?.samples||[]).filter(p=>coordinate([p.latitude,p.longitude])).map(p=>({id,points:[[p.latitude,p.longitude]],line:false})));
        });
    }
    drawLeaflet(map){
        this.leafletLayers.forEach(layer=>layer.remove());this.leafletLayers=[];
        for(const shape of this.shapes()){
            const color=routeColor(shape.id);
            if(shape.line)this.leafletLayers.push(L.polyline(shape.points,{color,weight:4,opacity:.85}).addTo(map));
            else for(const p of shape.points)this.leafletLayers.push(L.circleMarker(p,{color,radius:4,fillOpacity:.7}).addTo(map));
        }
    }
    drawGoogle(map){
        this.googleLayers.forEach(layer=>layer.setMap(null));this.googleLayers=[];
        for(const shape of this.shapes()){
            const path=shape.points.map(([lat,lng])=>({lat,lng})),color=routeColor(shape.id);
            if(shape.line)this.googleLayers.push(new window.google.maps.Polyline({map,path,strokeColor:color,strokeWeight:4,strokeOpacity:.85}));
            else for(const p of path)this.googleLayers.push(new window.google.maps.Circle({map,center:p,radius:10,strokeColor:color,fillColor:color,fillOpacity:.7}));
        }
    }
    fit(map,data,google=false){
        if(!map)return;
        const positions=data.vehicles.filter(v=>this.checked.has(v.id)&&v.location).map(v=>[v.location.latitude,v.location.longitude]);
        positions.push(...this.shapes().flatMap(s=>s.points));if(!positions.length)return;
        if(google){const bounds=new window.google.maps.LatLngBounds();positions.forEach(([lat,lng])=>bounds.extend({lat,lng}));map.fitBounds(bounds,40);}
        else map.fitBounds(positions,{padding:[35,35],maxZoom:15,animate:false});
    }
}
