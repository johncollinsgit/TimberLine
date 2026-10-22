// Entirely synthetic, browser-only examples. Never seed a customer database.
import roadRoutes from './demo-routes.json';
const iso = (minutes=0) => new Date(Date.now()+minutes*60000).toISOString();
const day = offset => new Date(Date.now()+offset*86400000).toISOString().slice(0,10);
export function createDemo(){
    const names=['Van 01 · North crew','Van 02 · Downtown crew','Truck 03 · Install team','Van 04 · Service crew','Truck 05 · Reserve','Van 06 · Inspection team'];
    const positions=names.map((_,i)=>roadRoutes.vehicles[i+1]?.[1].points.at(-1)||[34.821,-82.405]);
    const record=(id,device_id,details,status='open',at=iso(-5))=>({id,device_id,details,status,event_at:at});
    const jobs=[record(201,null,{source:'calendar',reference:'DEMO-appointment-201',title:'Panel inspection · Downtown',crm_status:'confirmed',assigned_user:'Demo dispatcher',device_id:2,latitude:34.8534,longitude:-82.3975,crm_start:iso(-45),crm_end:iso(35),skills:['inspection'],materials:{}},'active'),record(202,null,{source:'calendar',reference:'DEMO-appointment-202',title:'Service visit · North Main',crm_status:'confirmed',device_id:1,latitude:34.886,longitude:-82.393,crm_start:iso(55),crm_end:iso(115),skills:['service'],materials:{wire:40}},'active'),record(203,null,{source:'calendar',reference:'DEMO-appointment-203',title:'Lighting repair · Eastside',crm_status:'confirmed',latitude:34.871,longitude:-82.349,crm_start:iso(20),crm_end:iso(80),skills:['service'],materials:{wire:20}},'active'),record(204,null,{source:'pipeline',reference:'DEMO-opportunity-204',title:'New installation · Southside',crm_status:'open',device_id:3,latitude:34.819,longitude:-82.371,scheduled_start:iso(100),scheduled_end:iso(180),skills:['installation'],materials:{fixtures:6}},'active'),record(205,null,{source:'calendar',reference:'DEMO-appointment-205',title:'Service follow-up · Main Street',device_id:1,crm_start:iso(-100),crm_end:iso(-65),latitude:34.85,longitude:-82.4,skills:['service'],materials:{}},'active'),record(206,null,{source:'calendar',reference:'DEMO-appointment-206',title:'Second nearby service visit',device_id:1,crm_start:iso(-90),crm_end:iso(-60),latitude:34.85,longitude:-82.39,skills:['service'],materials:{}},'active')];
    const addresses=['100 E North St, Greenville, SC','801 N Main St, Greenville, SC','410 E North St, Greenville, SC','601 S Main St, Greenville, SC','206 S Main St, Greenville, SC','300 River St, Greenville, SC'];
    jobs.forEach((j,i)=>{j.details.crm_address=addresses[i];j.details.customer_name=['Oak Street Offices','North Main Workshop','Eastside Studio','Southside Lighting','Main Street Shop','River Street Office'][i];});
    // Presentation-only schedule. These are not HighLevel Calendar records.
    const calendarExamples=[
        {id:207,title:'DEMO — termite callback',customer:'Demo Customer A',street:'100 Demo Lane, Greenville, SC (fictional)',van:1,start:'11:15',end:'12:00',skills:['termite'],materials:{'bait stations':6}},
        {id:208,title:'DEMO — routine pest inspection',customer:'Demo Customer B',street:'200 Example Court, Greenville, SC (fictional)',van:1,start:'12:30',end:'13:15',skills:['general pest'],materials:{'inspection traps':4}},
        {id:209,title:'DEMO — rodent follow-up',customer:'Demo Customer C',street:'300 Sample Way, Greenville, SC (fictional)',van:2,start:'14:00',end:'14:30',skills:['rodent'],materials:{'rodent traps':3}},
    ];
    const storyDate=new Intl.DateTimeFormat('en-CA',{timeZone:'America/New_York',year:'numeric',month:'2-digit',day:'2-digit'}).format(new Date());
    const offset=new Intl.DateTimeFormat('en-US',{timeZone:'America/New_York',timeZoneName:'longOffset'}).formatToParts(new Date()).find(part=>part.type==='timeZoneName').value.replace('GMT','')||'+00:00';
    for(const item of calendarExamples)jobs.push(record(item.id,null,{source:'calendar',reference:`DEMO-job-${item.id}`,calendar_event_id:`DEMO-event-${item.id}`,synthetic_location:true,title:item.title,customer_name:item.customer,crm_address:item.street,device_id:item.van,crm_start:`${storyDate}T${item.start}:00${offset}`,crm_end:`${storyDate}T${item.end}:00${offset}`,skills:item.skills,materials:item.materials,material_units:Object.fromEntries(Object.keys(item.materials).map(name=>[name,'each']))},'active'));
    const crews=['Alex + Jordan','Taylor + Morgan','Sam + Casey','Riley + Jamie','','Drew + Avery'];
    return {demo:true,workspace:{name:'Demo · Greenville Service Company'},vehicles:names.map((name,i)=>({id:i+1,device_id:`DEMO-${i+1}`,name,costs:{fuel:[420,510,640,480,120,360][i],maintenance:[180,220,260,300,350,160][i],insurance:[135,140,160,145,150,130][i]},location:i===4?null:{latitude:positions[i][0],longitude:positions[i][1],reported_at:iso(i===3?-95:-3),older_reading:i===3}})),
        route_range:{today:day(0),yesterday:day(-1),earliest:day(-29),timezone:'America/New_York',retention_days:30},
        connection:{status:'connected',label:'Demo Bouncie account',health:{status:'connected',checked_at:iso(-1),unmapped_devices:2}},
        settings:{retention_days:30,policy_approved:true,tracking_enabled:true,vehicle_limit:100},subscription:{required:false,has_access:true,setup_allowed:false,collection_active:true},map_key:null,support_email:'support@theeverbranch.com',
        operations:{calendar_events:calendarExamples.map(item=>({id:`DEMO-event-${item.id}`,job_id:item.id,synthetic:true})),job_source:{type:'calendar',id:'demo-service-calendar',synced_at:iso(-2)},jobs,
            profiles:names.map((_,i)=>record(100+i,i+1,{crew:crews[i],available:i===0||i===2||i===5,skills:i===0?['service','termite','general pest']:i===1?['inspection','service','rodent','general pest']:i===2?['installation']:i===5?['inspection','service']:['service'],materials:{wire:i===5?80:100,fixtures:i===2?12:2,...(i===0?{'bait stations':12,'inspection traps':10}:i===1?{'rodent traps':8,'inspection traps':12}:{})}})),
            telemetry:names.map((_,i)=>record(120+i,i+1,{odometer:[48320,72300,32910,90800,112400,41500][i],device_connection:i===4?'disconnected':'connected',battery:i===4?'low':'normal',timestamps:{odometer:iso(i===4?-240:-3)}},'open',iso(i===4?-240:-3))),
            trips:[{...record(301,1,{started_at:iso(-130),ended_at:iso(-75),distance_miles:31.8,duration_seconds:3300,idle_seconds:420,expected_miles:18,baseline_reference:'Demo approved service route',extra_miles:5,extra_percent:30,review_status:'pending'},'completed'),drive_seconds:2880,candidates:[{id:205,title:jobs[4].details.title},{id:206,title:jobs[5].details.title}],route_review:'review'},
                {...record(302,2,{started_at:iso(-90),ended_at:iso(-48),distance_miles:12.4,duration_seconds:2520,idle_seconds:360,expected_miles:11.5,baseline_reference:'Demo planned route',extra_miles:5,extra_percent:30,job_id:201,review_status:'pending'},'completed'),drive_seconds:2160,candidates:[{id:201,title:jobs[0].details.title}],route_review:'within_tolerance'},
                {...record(303,3,{started_at:iso(-200),ended_at:iso(-140),distance_miles:22.6,duration_seconds:3600,idle_seconds:180,extra_miles:5,extra_percent:30,review_status:'pending'},'completed'),drive_seconds:3420,candidates:[],route_review:'baseline_needed'}],
            service_plans:[record(401,1,{title:'Oil and filter change',assignee:'Fleet manager',due_miles:48000,interval_miles:5000,estimated_cost:110}),record(402,3,{title:'Annual safety inspection',assignee:'Service coordinator',due_date:day(8),interval_days:365,estimated_cost:185}),record(403,4,{title:'Brake inspection',assignee:'Fleet manager',due_date:day(-3),estimated_cost:240}),record(404,2,{title:'Tire rotation and alignment',assignee:'Service coordinator',due_date:day(3),estimated_cost:165}),record(405,6,{title:'Transmission service',assignee:'Fleet manager',due_date:day(12),estimated_cost:320}),record(406,5,{title:'Battery replacement',assignee:'Fleet manager',due_date:day(-2),estimated_cost:215}),record(407,1,{title:'Replace cabin and engine filters',due_date:day(20),estimated_cost:85})],
            maintenance_tasks:[record(416,5,{plan_id:406,title:'Battery replacement',assignee:'Fleet manager',reason:'Service date reached'}),record(411,1,{plan_id:401,title:'Oil and filter change',assignee:'Fleet manager',reason:'Mileage threshold reached'}),record(413,4,{plan_id:403,title:'Brake inspection',assignee:'Fleet manager',reason:'Service date reached'})],
            service_logs:[record(420,2,{title:'Oil and filter change',odometer:70000,serviced_at:day(-3),actual_cost:105,notes:'Demo invoice SV-1042; oil and filter replaced'},'completed'),record(421,3,{title:'Four new tires',odometer:32500,serviced_at:day(-7),actual_cost:860,notes:'Fictional invoice SV-1038'},'completed'),record(422,6,{title:'Brake pads and fluid',odometer:41000,serviced_at:day(-12),actual_cost:380,notes:'Fictional invoice SV-1031'},'completed')],
            alerts:[record(501,5,{type:'battery',value:'low',assignee:'Fleet manager',resolution:''}),record(502,4,{type:'check_engine',value:'ON',assignee:'Service coordinator',resolution:'Diagnostic appointment booked'},'in_progress'),record(503,2,{type:'battery',value:'low',assignee:'Fleet manager',resolution:'Demo battery replaced and voltage checked'},'resolved',iso(-2880))]}};
}
export function demoRequest(d,path,method,body){
    if(path==='bootstrap')return d;
    if(path.startsWith('operations/vehicles/') && path.includes('/history?')){
        const id=Number(path.split('/')[2]),q=new URLSearchParams(path.split('?')[1]),start=q.get('start'),end=q.get('end');
        return {demo:true,vehicle_id:id,status:id===5?'no_trips':'ready',message:id===5?'No completed fictional trips for this reserve truck.':'Fictional driving routes along real roads; not recorded vehicle movements.',range:{start,end,timezone:'America/New_York'},samples:[],trips:(roadRoutes.vehicles[id]||[]).map((route,index)=>({id:`demo-route-${id}-${index}`,segments:[route.points],trace_segments:[true],geometry_limited:false,distance_miles:route.distance_miles,started_at:iso(index?-120:-240),ended_at:iso(index?-80:-210)}))};
    }
    if(path==='operations/sources')return {calendars:[{id:'demo-service-calendar',name:'Demo service appointments'}],pipelines:[{id:'demo-work-pipeline',name:'Demo scheduled work'}]};
    if(path==='operations/sync')return {saved:true,count:d.operations.jobs.length};
    if(path.endsWith('/path')){const trip=d.operations.trips.find(t=>t.id===Number(path.split('/')[2])),route=roadRoutes.vehicles[trip?.device_id]?.[0];return {message:'Fictional driving route along real roads. This is not a recorded vehicle movement.',points:[],trips:route?[{segments:[route.points],trace_segments:[true]}]:[],truncated:false};}
    const id=Number(path.split('/')[2]);
    if(path.endsWith('/dispatch')){
        const job=d.operations.jobs.find(j=>j.id===id),j=job?.details,start=Date.parse(j?.scheduled_start||j?.crm_start),end=Date.parse(j?.scheduled_end||j?.crm_end);
        if(!j||j.latitude==null||j.longitude==null||!Number.isFinite(start)||!Number.isFinite(end)||end<=start)throw new Error('Choose a saved job address and start and finish times first.');
        const suggestions=d.vehicles.flatMap(v=>{
            const p=d.operations.profiles.find(p=>p.device_id===v.id)?.details||{};
            if(!p.available||!p.crew||!v.location||v.location.older_reading||(j.skills||[]).some(s=>!(p.skills||[]).includes(s))||Object.entries(j.materials||{}).some(([name,qty])=>(p.materials?.[name]||0)<qty))return [];
            if(d.operations.jobs.some(other=>{const o=other.details;if(other.id===id||Number(o.device_id)!==v.id)return false;const a=Date.parse(o.scheduled_start||o.crm_start),b=Date.parse(o.scheduled_end||o.crm_end);return !Number.isFinite(a)||!Number.isFinite(b)||(a<end&&b>start);}))return [];
            const rad=n=>n*Math.PI/180,a=v.location.latitude,b=j.latitude,h=Math.sin(rad(b-a)/2)**2+Math.cos(rad(a))*Math.cos(rad(b))*Math.sin(rad(j.longitude-v.location.longitude)/2)**2;
            return [{device_id:v.id,name:v.name,crew:p.crew,straight_line_miles:Math.round(3958.8*2*Math.asin(Math.sqrt(Math.min(1,h)))*10)/10,reported_at:v.location.reported_at}];
        }).sort((a,b)=>a.straight_line_miles-b.straight_line_miles);
        return {basis:'Demo suggestions use confirmed skills and stock, schedule availability, and recent locations. Miles are straight-line, not ETA.',suggestions};
    }
    const lists={profiles:'profiles',jobs:'jobs',alerts:'alerts',trips:'trips'};
    const resource=path.split('/')[1];
    if(lists[resource]){
        const item=d.operations[lists[resource]].find(r=>resource==='profiles'?r.device_id===id:r.id===id);
        if(!item)throw new Error('Demo record is unavailable.');
        if(resource==='jobs'&&Object.hasOwn(body,'address')){
            const source=d.operations.jobs.find(j=>j.id===body.location_job_id),p=source?.details;
            const same=p&&(p.address_override||p.crm_address)===body.address;
            body={...body,address_override:body.address===item.details.crm_address?null:body.address,latitude:same?p.latitude:null,longitude:same?p.longitude:null,location_status:same?'ready':'check_address'};
            delete body.address;
        }
        item.details={...item.details,...body};if(resource==='alerts')item.status=body.status;
        if(resource==='trips'){const threshold=body.expected_miles==null?null:body.expected_miles+Math.max(body.extra_miles,body.expected_miles*body.extra_percent/100);item.route_review=threshold==null?'baseline_needed':item.details.distance_miles>threshold?'review':'within_tolerance';}
        return {saved:true};
    }
    if(resource==='plans') {d.operations.service_plans.push({id:Date.now(),device_id:body.device_id,details:body,status:'open',event_at:iso()});return {saved:true};}
    if(resource==='service'){
        const plan=d.operations.service_plans.find(p=>p.id===body.plan_id);if(!plan||plan.status!=='open')throw new Error('This demo service is already recorded.');
        const old=plan.details;if(old.interval_miles||old.interval_days){const next={...old,due_miles:old.interval_miles?body.odometer+old.interval_miles:null,due_date:old.interval_days?new Date(Date.parse(body.serviced_at)+old.interval_days*86400000).toISOString().slice(0,10):null};d.operations.service_plans.push({id:Date.now()+1,device_id:plan.device_id,details:next,status:'open',event_at:iso()});}plan.status='completed';d.operations.service_logs.unshift({id:Date.now(),device_id:plan.device_id,details:{...body,title:plan.details.title},status:'completed',event_at:iso()});d.operations.maintenance_tasks=d.operations.maintenance_tasks.filter(t=>t.details.plan_id!==body.plan_id);return {saved:true};
    }
    throw new Error('Connection and policy setup use your real account. Exit the demo to configure them.');
}
