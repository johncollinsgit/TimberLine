// Original Everbranch mark, verified against the production app and canonical
// TimberLine public/brand/everbranch-mark.svg. Color inherits its existing context.
export default function BrandLeaf({size=24}:{size?:number}) {
 return <span aria-hidden="true" className="everbranch-leaf" style={{display:'inline-block',width:size,height:size,flexShrink:0,backgroundColor:'currentColor',maskImage:'url(/brand/everbranch-mark.svg)',maskSize:'contain',maskPosition:'center',maskRepeat:'no-repeat',WebkitMaskImage:'url(/brand/everbranch-mark.svg)',WebkitMaskSize:'contain',WebkitMaskPosition:'center',WebkitMaskRepeat:'no-repeat'}}/>;
}
