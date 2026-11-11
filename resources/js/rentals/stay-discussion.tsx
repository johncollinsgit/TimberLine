'use client';
import {MessageSquare,Trash2} from 'lucide-react';
import type {ReactNode} from 'react';

export default function StayDiscussion({comments=[],attachments,onDelete,busy,timezone='UTC'}:{comments?:any[];attachments:(ids:string[])=>ReactNode;onDelete:(id:string)=>void;busy:boolean;timezone?:string}){
 return <section className="stay-discussion" aria-label="Stay discussion">
  <h3 className="comments-heading"><MessageSquare size={17}/> Stay discussion <span className="badge">{comments.length}</span></h3>
  <p className="muted stay-discussion-intro">Notes, photos, and files stay attached to this specific week.</p>
  {comments.length?comments.map(c=><article className="comment stay-comment" key={c.id}>
   <span className="avatar">{(c.author||'Owner').split(' ').slice(0,2).map((v:string)=>v[0]).join('')}</span>
   <div><strong>{c.author}<small>{new Date(c.date).toLocaleDateString('en-US',{month:'short',day:'numeric',year:'numeric',timeZone:timezone})}</small></strong>
    {c.body&&<p>{c.body}</p>}{attachments(c.attachmentIds||[])}
   </div>
   {c.canDelete&&<button type="button" className="icon-btn delete-comment" aria-label="Delete stay comment" title="Delete comment" disabled={busy} onClick={()=>onDelete(c.id)}><Trash2 size={16}/></button>}
  </article>):<div className="stay-discussion-empty"><MessageSquare size={23}/><p>No comments yet. Add a note, photo, or file below.</p></div>}
 </section>;
}
