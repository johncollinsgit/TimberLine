import type {CartValidationsGenerateRunInput,CartValidationsGenerateRunResult,ValidationError} from "../generated/api";
export function cartValidationsGenerateRun(input:CartValidationsGenerateRunInput):CartValidationsGenerateRunResult {
  const errors:ValidationError[]=[];
  const reject=(message:string)=>errors.push({message,target:"$.cart"});
  if(input.cart.lines.length>0&&input.cart.buyerIdentity?.customer?.hasWholesaleAccess!==true){
    reject("Wholesale ordering is available to approved partners. Sign in with your approved wholesale account or apply for wholesale access.");
  }
  const services=new Set(["custom-label-set-up","use-my-custom-label"]);
  for(const line of input.cart.lines){
    if(line.merchandise.__typename!=="ProductVariant")continue;
    const variant=line.merchandise;
    if(services.has(variant.product.handle)){
      if(line.quantity!==1)reject("Custom-label services are charged once per order. Set the service quantity to 1.");
    }else{
      const regular=/wax\s?melt|candle|cotton wick|cedar wick|wood wick/i.test(variant.title+" "+variant.product.handle);
      if((regular||!variant.product.singleQuantity)&&(line.quantity<3||line.quantity%3!==0))reject("Order each candle size and wax melt scent in multiples of 3 (3, 6, 9). Update your cart before checkout.");
    }
  }
  if(input.shop?.labelProgram?.value==="true"&&input.cart.lines.length>0){
    const setup="custom-label-set-up",reuse="use-my-custom-label";
    const id=(line:typeof input.cart.lines[number])=>line.merchandise.__typename==="ProductVariant"?line.merchandise.product.handle:"";
    const fees=input.cart.lines.filter(line=>[setup,reuse].includes(id(line)));
    const selected=input.cart.lines.filter(line=>![setup,reuse].includes(id(line))&&!!line.receipt?.value);
    const choice=input.cart.labelChoice?.value;
    if(choice!=="Yes"&&choice!=="No")reject("Please review the custom-label choice in your cart before checking out.");
    else if(choice==="No"){
      if(fees.length||selected.length)reject("Custom-label items are still in your order. Review your label choice in the cart.");
    }else{
      const fee=fees[0],receipt=fee?.receipt?.value,revision=fee?.revision?.value,mode=fee?.mode?.value;
      const validReceipt=!!receipt&&/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(receipt);
      if(fees.length!==1||fee.quantity!==1||!validReceipt||!revision||!["new","reuse","legacy"].includes(mode||"")||id(fee)!==(mode==="new"?setup:reuse))reject("Choose your custom label in the cart. Each order needs exactly one matching setup or reuse fee.");
      else if(!selected.length||selected.some(line=>line.receipt?.value!==receipt||line.revision?.value!==revision||line.mode?.value!==mode||line.quantity%3!==0))reject("Select the candle groups for your label in the cart. Candle quantities must be multiples of 3.");
    }
  }
  return {operations:[{validationAdd:{errors}}]};
}
