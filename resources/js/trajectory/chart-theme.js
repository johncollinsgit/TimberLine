const font = '-apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
const compact = new Intl.NumberFormat('en-US', {style:'currency',currency:'USD',notation:'compact',maximumFractionDigits:1});

export function chartPresentation(canvas, money, type, extra = {}) {
  const wrapper=canvas.parentElement;
  const legend=document.createElement('div');
  legend.className='tr-chart-legend';
  legend.setAttribute('role','group');
  legend.setAttribute('aria-label',`${canvas.getAttribute('aria-label')} — show or hide series`);
  wrapper.after(legend);
  const tooltip=document.createElement('div');
  tooltip.className='tr-chart-tooltip';tooltip.hidden=true;
  wrapper.append(tooltip);
  function makeLegend(chart) {
    const focused=[...legend.children].indexOf(document.activeElement);
    legend.replaceChildren();
    const items=type==='doughnut'?chart.data.labels.map((label,i)=>({label,color:chart.data.datasets[0].backgroundColor[i],i,visible:chart.getDataVisibility(i)})):chart.data.datasets.map((d,i)=>({label:d.label,color:d.borderColor||d.backgroundColor,i,visible:chart.isDatasetVisible(i),dashed:d.borderDash?.length}));
    items.forEach(item=>{
      const button=document.createElement('button');button.type='button';button.setAttribute('aria-pressed',String(item.visible));
      const swatch=document.createElement('span');swatch.className='tr-chart-swatch';swatch.style.borderColor=item.color;if(item.dashed)swatch.style.borderTopStyle='dashed';
      button.append(swatch,document.createTextNode(item.label||'Value'));
      button.onclick=()=>{if(type==='doughnut')chart.toggleDataVisibility(item.i);else chart.setDatasetVisibility(item.i,!chart.isDatasetVisible(item.i));chart.update();};
      legend.append(button);
    });
    if(focused>=0)legend.children[focused]?.focus({preventScroll:true});
  }
  const plugin={id:'trajectoryPresentation',afterUpdate:makeLegend,afterDraw(chart){
    if(type==='doughnut')return;
    const {ctx,chartArea:a,scales:{x}}=chart;
    if(!a)return;
    ctx.save();
    if(extra.boundary !== null && extra.boundary !== undefined){
      const bx=x.getPixelForValue(extra.boundary);
      ctx.strokeStyle='#9caab4';ctx.lineWidth=1;ctx.setLineDash([3,5]);ctx.beginPath();ctx.moveTo(bx,a.top);ctx.lineTo(bx,a.bottom);ctx.stroke();ctx.setLineDash([]);
      ctx.fillStyle='#5a6873';ctx.font=`11px ${font}`;ctx.fillText('Projection →',Math.min(bx+8,a.right-82),a.top-12);
    }
    const active=chart.getActiveElements()[0];
    if(active){const px=active.element.x;ctx.strokeStyle='#9caab4';ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(px,a.top);ctx.lineTo(px,a.bottom);ctx.stroke();}
    ctx.restore();
  },afterDestroy(){legend.remove();tooltip.remove();}};
  return {plugins:[plugin],options:{
    responsive:true,maintainAspectRatio:false,
    animation:matchMedia('(prefers-reduced-motion: reduce)').matches?false:{duration:250},
    layout:{padding:{top:extra.boundary!==undefined?30:12,right:12}},
    interaction:{intersect:false,mode:'index'},
    plugins:{legend:{display:false},tooltip:{enabled:false,external:({chart,tooltip:model})=>{
      tooltip.hidden=!model.opacity;if(!model.opacity)return;
      tooltip.replaceChildren();
      const heading=document.createElement('strong');heading.className='tr-tooltip-heading';heading.textContent=model.title?.join(' ')||'';tooltip.append(heading);
      model.dataPoints.forEach(point=>{const row=document.createElement('div');row.className='tr-tooltip-row';const label=document.createElement('span');label.textContent=point.dataset.label||point.label;const value=document.createElement('strong');const parsed=point.parsed.y??point.parsed;value.textContent=Number.isFinite(parsed)?money(parsed):'No data';row.append(label,value);tooltip.append(row);});
      tooltip.style.left=`${Math.max(0,Math.min(model.caretX+16,wrapper.clientWidth-tooltip.offsetWidth))}px`;
      tooltip.style.top=`${Math.max(0,Math.min(model.caretY-tooltip.offsetHeight-12,wrapper.clientHeight-tooltip.offsetHeight))}px`;
    }}},
    scales:type==='doughnut'?{}:{
      x:{grid:{display:false},border:{display:false},ticks:{...(extra.tickLabels?{callback:v=>extra.tickLabels[v]}:{}),maxTicksLimit:extra.showAllTicks?12:6,maxRotation:0,autoSkip:!extra.showAllTicks,color:'#667580',padding:12,font:{family:font,size:11}}},
      y:{beginAtZero:extra.beginAtZero??false,border:{display:false},grid:{color:ctx=>ctx.tick.value===0?'#b8c4cc':'#edf0f3',drawTicks:false},ticks:{maxTicksLimit:6,padding:12,color:'#667580',callback:v=>compact.format(v/100),font:{family:font,size:11}}},
    },
  }};
}
