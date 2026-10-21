import { W as R, S as O, a as T, O as B, H as C, D as E, G as f, M as G, b as v, C as F, c as W, d as H, B as L, V as c, e as x, p as V, f as q, g as D, m as P, Q as y, h as k, E as A } from "./viewer-Ch5YCX_w.js";
const S = [[0, 1], [1, 2], [2, 3], [3, 4], [0, 5], [5, 6], [6, 7], [7, 8], [5, 9], [9, 10], [10, 11], [11, 12], [9, 13], [13, 14], [14, 15], [15, 16], [13, 17], [0, 17], [17, 18], [18, 19], [19, 20]];
function p(w) {
  w?.traverse((t) => {
    t.geometry?.dispose();
    for (const s of Array.isArray(t.material) ? t.material : [t.material]) if (s) {
      for (const i of Object.values(s)) i?.isTexture && i.dispose();
      s.dispose();
    }
  });
}
class Q {
  constructor(t, s) {
    this.config = s, this.version = 0, this.disposed = !1, this.offset = { x: 0, y: 0 }, this.userRotation = [0, 0, 0], this.zoom = 1, this.palmMm = 80, this.masking = !0, this.renderer = new R({ canvas: t, alpha: !0, antialias: !0, preserveDrawingBuffer: !0 }), this.renderer.setPixelRatio(Math.min(devicePixelRatio, 2)), this.renderer.setClearColor(0, 0), this.renderer.outputColorSpace = O, this.scene = new T(), this.camera = new B(-1, 1, 1, -1, 0.1, 1e4), this.camera.position.z = 2e3, this.scene.add(new C(16777215, 6904912, 3));
    const i = new E(16777215, 3);
    i.position.set(-200, 300, 700), this.scene.add(i), this.object = new f(), this.scene.add(this.object), this.object.visible = !1, this.occlusion = new f(), this.scene.add(this.occlusion), this.occlusion.visible = !1;
    const n = new G({ colorWrite: !1, depthWrite: !0, depthTest: !0 });
    for (const o of S) {
      const e = new v(new F(1, 1, 1, 8), n);
      e.renderOrder = -1, this.occlusion.add(e);
    }
    this.joints = [];
    for (let o = 0; o < 21; o++) {
      const e = new v(new W(1, 8, 6), n);
      e.renderOrder = -1, this.occlusion.add(e), this.joints.push(e);
    }
  }
  async load(t) {
    const s = ++this.version;
    this.object.visible = !1;
    const { scene: i } = await new H().loadAsync(t);
    if (this.disposed || s !== this.version) {
      p(i);
      return;
    }
    p(this.model), this.model && this.object.remove(this.model);
    const n = new L().setFromObject(i), o = n.getSize(new c()), e = Math.max(o.x, o.y, o.z);
    if (!Number.isFinite(e) || e <= 0)
      throw p(i), new Error("This model has no visible geometry.");
    const h = new c(...this.config.grip).multiply(o).add(n.min), l = new f();
    i.position.sub(h), l.add(i), l.scale.setScalar(this.config.lengthMm / e);
    const a = new f();
    a.add(l), a.rotation.set(...this.config.rotationDeg.map(x.degToRad)), this.model = a, this.object.add(a);
  }
  resize(t, s) {
    this.width = t, this.height = s, this.renderer.setSize(t, s, !1), Object.assign(this.camera, { left: -t / 2, right: t / 2, top: s / 2, bottom: -s / 2 }), this.camera.updateProjectionMatrix();
  }
  update(t, s) {
    if (!this.model || !s.videoWidth || !this.width) return;
    const i = V(t, q(s.videoWidth, s.videoHeight, this.width, this.height)), n = D(i), o = P(n, this.palmMm);
    if (!i || n < 15 || o <= 0) {
      this.object.visible = !1, this.occlusion.visible = !1;
      return;
    }
    const e = i.map((r) => new c(r.x, r.y, r.z)), h = e[17].clone().sub(e[5]).normalize(), l = e[9].clone().sub(e[0]).normalize(), a = new c().crossVectors(h, l).normalize();
    if (a.lengthSq() < 0.01) {
      this.object.visible = !1, this.occlusion.visible = !1;
      return;
    }
    const z = new c().crossVectors(a, h).normalize(), u = new y().setFromRotationMatrix(new k().makeBasis(h, z, a));
    u.multiply(new y().setFromEuler(new A(...this.userRotation.map(x.degToRad))));
    const d = e[0].clone().lerp(e[9], 0.67);
    d.x += this.offset.x, d.y += this.offset.y, this.object.visible ? (this.object.position.lerp(d, 0.35), this.object.quaternion.slerp(u, 0.35), this.object.scale.lerp(new c().setScalar(o * this.zoom), 0.35)) : (this.object.position.copy(d), this.object.quaternion.copy(u), this.object.scale.setScalar(o * this.zoom)), this.object.visible = !0, this.occlusion.visible = this.masking, S.forEach(([r, m], M) => {
      const b = this.occlusion.children[M], g = e[m].clone().sub(e[r]);
      b.position.copy(e[r]).lerp(e[m], 0.5), b.quaternion.setFromUnitVectors(new c(0, 1, 0), g.clone().normalize());
      const j = n * (r === 0 ? 0.065 : 0.044);
      b.scale.set(j, g.length(), j);
    }), this.joints.forEach((r, m) => {
      r.position.copy(e[m]), r.scale.setScalar(n * 0.044);
    });
  }
  hide() {
    this.object.visible = !1, this.occlusion.visible = !1;
  }
  render() {
    this.renderer.render(this.scene, this.camera);
  }
  close() {
    this.disposed = !0, ++this.version, p(this.scene), this.renderer.dispose();
  }
}
export {
  Q as HandScene
};
