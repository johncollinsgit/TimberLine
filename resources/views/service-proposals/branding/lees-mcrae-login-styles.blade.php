<style>
.lmc-login{margin:0 0 30px;isolation:isolate}
.lmc-login__stage{position:relative;overflow:hidden;display:grid;align-content:center;gap:27px;min-height:210px;padding:34px 28px 28px;border:1px solid #234c40;border-radius:16px;background:radial-gradient(ellipse at 49% 0%,#234c40 0%,#102b24 62%);color:#fff}
.lmc-login__stage::before{content:"";position:absolute;inset:0;background:linear-gradient(120deg,transparent 20%,#ffffff04 45%,transparent 68%);pointer-events:none}
.lmc-login__mark{position:relative;width:100%;aspect-ratio:438/85;perspective:650px;perspective-origin:42% 50%}
.lmc-login .lmc-login__letters,.lmc-login .lmc-login__crest{display:block;width:100%;height:100%;fill:none;stroke:none;overflow:visible}
.lmc-login__plane{position:absolute;left:34.246575%;top:0;width:15.296804%;height:100%;transform:none;transform-origin:50% 48%;transform-style:preserve-3d}
.lmc-login__edge{opacity:0;filter:brightness(.45) saturate(.7)}
.lmc-login__light{opacity:0;transform:translateX(0) skewX(-14deg);transform-origin:0 0}
.lmc-login__caption{display:flex;align-items:center;justify-content:center;gap:14px;font:500 10px/1.5 Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;letter-spacing:.19em;text-transform:uppercase;color:#dfd4b2}
.lmc-login__caption span{height:1px;width:28px;background:#c5a45d80}
@media(prefers-reduced-motion:no-preference){
    .lmc-login__plane{animation:lmc-login-settle 2800ms cubic-bezier(.22,.61,.36,1) both}
    .lmc-login__edge{animation:lmc-login-edge 2800ms ease-out both}
    .lmc-login__light{animation:lmc-login-light 2600ms ease-in-out 100ms both}
    .lmc-login__letters{animation:lmc-login-letters 1800ms ease-out both}
}
@keyframes lmc-login-settle{
    0%{transform:translateY(3px) rotateX(9deg) rotateY(-27deg) rotateZ(-1deg)}
    38%{transform:translateY(-1px) rotateX(-4deg) rotateY(17deg) rotateZ(.4deg)}
    68%{transform:rotateX(2deg) rotateY(-7deg)}
    87%{transform:rotateY(2deg)}
    100%{transform:none}
}
@keyframes lmc-login-edge{0%,38%{opacity:.8}70%{opacity:.35}100%{opacity:0}}
@keyframes lmc-login-light{0%{opacity:0;transform:translateX(0) skewX(-14deg)}12%{opacity:.8}54%{opacity:.8}72%,100%{opacity:0;transform:translateX(140px) skewX(-14deg)}}
@keyframes lmc-login-letters{0%{opacity:.78}100%{opacity:1}}
@media(max-width:480px){.lmc-login__stage{min-height:164px;padding:28px 20px 23px;gap:23px}.lmc-login__caption{font-size:9px;letter-spacing:.15em}.lmc-login{margin-bottom:25px}}
@media(forced-colors:active){.lmc-login__stage{background:#102b24;forced-color-adjust:none}}
@media print{.lmc-login__plane,.lmc-login__edge,.lmc-login__light,.lmc-login__letters{animation:none!important}.lmc-login__edge,.lmc-login__light{display:none}}
</style>
