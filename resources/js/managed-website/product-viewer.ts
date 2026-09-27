import type { AnimationClip } from 'three';
import type { GLTF } from 'three/examples/jsm/loaders/GLTFLoader.js';

type Hotspot = {
    id?: string;
    label?: string;
    body?: string;
    node_name?: string;
};

type ViewerVariant = {
    id?: string;
    label?: string;
    model_url?: string;
    poster_url?: string;
    poster_alt?: string;
    raise_clip?: string;
    lower_clip?: string;
    hotspots?: Hotspot[];
};

type ViewerRoot = HTMLElement & {
    dataset: DOMStringMap & {
        modelUrl?: string;
        posterUrl?: string;
        raiseClip?: string;
        lowerClip?: string;
        hotspots?: string;
        productVariants?: string;
        activeVariant?: string;
        variantListeners?: string;
    };
};

const parseVariants = (value?: string): ViewerVariant[] => {
    try {
        const parsed = JSON.parse(value || '[]');

        return Array.isArray(parsed) ? parsed.filter((item): item is ViewerVariant => Boolean(item && typeof item === 'object' && item.model_url)) : [];
    } catch {
        return [];
    }
};

const parseHotspots = (value?: string): Hotspot[] => {
    try {
        const parsed = JSON.parse(value || '[]');

        return Array.isArray(parsed) ? parsed.filter((item): item is Hotspot => Boolean(item && typeof item === 'object')) : [];
    } catch {
        return [];
    }
};

async function mount(root: ViewerRoot): Promise<void> {
    if (root.dataset.mounted || document.body.dataset.previewMode === 'editor') return;
    root.dataset.mounted = 'true';

    const variants = parseVariants(root.dataset.productVariants);
    const activeVariant = variants.find((variant) => variant.id === root.dataset.activeVariant) || variants[0];
    if (activeVariant) {
        root.dataset.activeVariant = activeVariant.id || '';
        root.dataset.modelUrl = activeVariant.model_url;
        root.dataset.posterUrl = activeVariant.poster_url;
        root.dataset.raiseClip = activeVariant.raise_clip || 'raise';
        root.dataset.lowerClip = activeVariant.lower_clip || 'lower';
        root.dataset.hotspots = JSON.stringify(activeVariant.hotspots || []);
        const poster = root.querySelector<HTMLImageElement>('.product-viewer__poster');
        if (poster && activeVariant.poster_url) {
            poster.src = activeVariant.poster_url;
            poster.alt = activeVariant.poster_alt || poster.alt;
        }
    }
    const variantButtons = [...root.querySelectorAll<HTMLButtonElement>('[data-product-viewer-variant]')];
    variantButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.productViewerVariant === root.dataset.activeVariant)));
    if (!root.dataset.variantListeners && variants.length > 1) {
        root.dataset.variantListeners = 'true';
        variantButtons.forEach((button) => button.addEventListener('click', () => {
            const next = parseVariants(root.dataset.productVariants).find((variant) => variant.id === button.dataset.productViewerVariant);
            if (!next || next.id === root.dataset.activeVariant) return;
            root.dispatchEvent(new Event('product-viewer:dispose'));
            delete root.dataset.mounted;
            root.classList.remove('is-ready');
            root.dataset.activeVariant = next.id || '';
            void mount(root);
        }));
    }

    const modelUrl = root.dataset.modelUrl;
    if (!modelUrl) return;

    const canvas = root.querySelector<HTMLElement>('[data-product-viewer-canvas]');
    const status = root.querySelector<HTMLElement>('[data-product-viewer-status]');
    const raiseButton = root.querySelector<HTMLButtonElement>('[data-product-viewer-action="raise"]');
    const lowerButton = root.querySelector<HTMLButtonElement>('[data-product-viewer-action="lower"]');
    const hotspotsLayer = root.querySelector<HTMLElement>('[data-product-viewer-hotspots]');
    if (!canvas || !status || !raiseButton || !lowerButton || !hotspotsLayer) return;

    try {
        const [{
            ACESFilmicToneMapping,
            AmbientLight,
            AnimationMixer,
            Box3,
            Clock,
            Color,
            DirectionalLight,
            LoopOnce,
            MathUtils,
            PerspectiveCamera,
            Scene,
            Vector3,
            WebGLRenderer,
        }, { GLTFLoader }, { OrbitControls }, { MeshoptDecoder }] = await Promise.all([
            import('three'),
            import('three/examples/jsm/loaders/GLTFLoader.js'),
            import('three/examples/jsm/controls/OrbitControls.js'),
            import('three/examples/jsm/libs/meshopt_decoder.module.js'),
        ]);

        const renderer = new WebGLRenderer({ alpha: true, antialias: true, powerPreference: 'high-performance' });
        renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
        renderer.outputColorSpace = 'srgb';
        renderer.toneMapping = ACESFilmicToneMapping;
        renderer.setClearColor(new Color(0xffffff), 0);
        renderer.domElement.setAttribute('aria-hidden', 'true');
        canvas.replaceChildren(renderer.domElement);

        const scene = new Scene();
        scene.add(new AmbientLight(0xffffff, 2.1));
        const key = new DirectionalLight(0xffffff, 2.6);
        key.position.set(4, 7, 6);
        scene.add(key);
        const fill = new DirectionalLight(0xffffff, 1.1);
        fill.position.set(-5, 2, -4);
        scene.add(fill);

        const camera = new PerspectiveCamera(32, 1, 0.01, 1000);
        const controls = new OrbitControls(camera, renderer.domElement);
        controls.enablePan = false;
        controls.enableDamping = true;
        controls.dampingFactor = 0.07;
        controls.minDistance = 0.5;
        controls.maxDistance = 25;
        controls.target.set(0, 0.7, 0);

        const response = await fetch(modelUrl, { credentials: 'same-origin' });
        if (!response.ok) throw new Error(`Model request failed with ${response.status}`);
        const binary = await response.arrayBuffer();
        const loader = new GLTFLoader();
        loader.setMeshoptDecoder(MeshoptDecoder);
        const gltf = await new Promise<GLTF>((resolve, reject) => {
            loader.parse(binary, '', resolve, reject);
        });

        const model = gltf.scene;
        scene.add(model);
        // Frame the largest animated pose, not merely the closed GLB pose.
        // A lift model otherwise looks correctly framed until the customer
        // raises it, at which point the shelves can leave the viewport.
        const restTransforms = new Map();
        model.traverse((object) => {
            restTransforms.set(object, {
                position: object.position.clone(),
                quaternion: object.quaternion.clone(),
                scale: object.scale.clone(),
            });
        });
        const restoreRestPose = () => {
            restTransforms.forEach((transform, object) => {
                object.position.copy(transform.position);
                object.quaternion.copy(transform.quaternion);
                object.scale.copy(transform.scale);
            });
            model.updateMatrixWorld(true);
        };
        const bounds = new Box3().setFromObject(model);
        const boundsMixer = new AnimationMixer(model);
        gltf.animations.forEach((clip) => {
            const action = boundsMixer.clipAction(clip);
            action.reset();
            action.setLoop(LoopOnce, 1);
            action.clampWhenFinished = true;
            action.play();
            boundsMixer.update(clip.duration);
            model.updateMatrixWorld(true);
            bounds.union(new Box3().setFromObject(model));
            boundsMixer.stopAllAction();
            restoreRestPose();
        });
        const size = bounds.getSize(new Vector3());
        const center = bounds.getCenter(new Vector3());
        const largestAxis = Math.max(size.x, size.y, size.z, 0.1);
        controls.target.copy(center);
        camera.near = Math.max(largestAxis / 1000, 0.01);
        camera.far = largestAxis * 100;
        camera.position.set(center.x + largestAxis * 1.32, center.y + largestAxis * 0.88, center.z + largestAxis * 1.62);
        camera.lookAt(center);
        controls.minDistance = largestAxis * 0.75;
        controls.maxDistance = largestAxis * 4.2;
        controls.update();

        const clips = new Map<string, AnimationClip>(gltf.animations.map((clip) => [clip.name, clip]));
        const mixer = new AnimationMixer(model);
        const lowerClip = clips.get(root.dataset.lowerClip || 'lower');
        const raiseClip = clips.get(root.dataset.raiseClip || 'raise');
        let state: 'closed' | 'raised' | 'moving' = 'closed';
        let destination: 'closed' | 'raised' = 'closed';
        let currentAction: ReturnType<typeof mixer.clipAction> | null = null;

        const updateControls = () => {
            const moving = state === 'moving';
            raiseButton.disabled = moving || state === 'raised' || !raiseClip;
            lowerButton.disabled = moving || state === 'closed' || !lowerClip;
        };

        const setClosedPose = () => {
            if (!lowerClip) return;
            mixer.stopAllAction();
            const action = mixer.clipAction(lowerClip);
            action.reset();
            action.setLoop(LoopOnce, 1);
            action.clampWhenFinished = true;
            action.play();
            mixer.update(lowerClip.duration);
            action.paused = true;
        };

        setClosedPose();
        updateControls();

        hotspotsLayer.replaceChildren();
        const hotspotElements: Array<{ button: HTMLButtonElement; nodeName: string }> = [];
        parseHotspots(root.dataset.hotspots).forEach((hotspot, index) => {
            if (!hotspot.node_name || !hotspot.label) return;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'product-viewer__hotspot';
            button.textContent = hotspot.label;
            button.dataset.hotspotId = hotspot.id || String(index);
            button.title = hotspot.body || hotspot.label;
            button.setAttribute('aria-label', hotspot.body ? `${hotspot.label}: ${hotspot.body}` : hotspot.label);
            button.hidden = true;
            button.addEventListener('click', () => {
                const isOpen = button.getAttribute('aria-expanded') === 'true';
                hotspotElements.forEach(({ button: candidate }) => candidate.setAttribute('aria-expanded', 'false'));
                button.setAttribute('aria-expanded', String(!isOpen));
            });
            hotspotsLayer.append(button);
            hotspotElements.push({ button, nodeName: hotspot.node_name });
        });

        const resize = () => {
            const { width, height } = canvas.getBoundingClientRect();
            if (!width || !height) return;
            camera.aspect = width / height;
            camera.updateProjectionMatrix();
            renderer.setSize(width, height, false);
        };
        const resizeObserver = new ResizeObserver(resize);
        resizeObserver.observe(canvas);
        resize();

        const updateHotspots = () => {
            const canvasRect = canvas.getBoundingClientRect();
            hotspotElements.forEach(({ button, nodeName }) => {
                const anchor = model.getObjectByName(nodeName);
                if (!anchor) return;
                const point = anchor.getWorldPosition(new Vector3()).project(camera);
                const visible = point.z > -1 && point.z < 1 && point.x > -1 && point.x < 1 && point.y > -1 && point.y < 1;
                button.hidden = !visible;
                if (visible) {
                    button.style.left = `${MathUtils.clamp((point.x * 0.5 + 0.5) * canvasRect.width, 10, canvasRect.width - 10)}px`;
                    button.style.top = `${MathUtils.clamp((-point.y * 0.5 + 0.5) * canvasRect.height, 10, canvasRect.height - 10)}px`;
                }
            });
        };

        const render = () => {
            controls.update();
            renderer.render(scene, camera);
            updateHotspots();
        };
        const clock = new Clock();
        let frame = 0;
        const tick = () => {
            frame = window.requestAnimationFrame(tick);
            mixer.update(clock.getDelta());
            render();
        };
        tick();

        mixer.addEventListener('finished', (event) => {
            if (event.action !== currentAction) return;
            state = destination;
            currentAction = null;
            status.textContent = state === 'raised' ? 'Product is raised.' : 'Product is lowered.';
            updateControls();
        });

        const play = (clip: typeof raiseClip, nextState: 'raised' | 'closed') => {
            if (!clip || state === 'moving') return;
            mixer.stopAllAction();
            currentAction = mixer.clipAction(clip);
            currentAction.reset();
            currentAction.setLoop(LoopOnce, 1);
            currentAction.clampWhenFinished = true;
            currentAction.play();
            state = 'moving';
            destination = nextState;
            status.textContent = nextState === 'raised' ? 'Raising product…' : 'Lowering product…';
            updateControls();
        };
        raiseButton.addEventListener('click', () => play(raiseClip, 'raised'));
        lowerButton.addEventListener('click', () => play(lowerClip, 'closed'));

        root.classList.add('is-ready');
        root.setAttribute('aria-busy', 'false');
        status.textContent = 'Interactive 3D product view ready. Drag to rotate or use the controls.';

        root.addEventListener('product-viewer:dispose', () => {
            window.cancelAnimationFrame(frame);
            resizeObserver.disconnect();
            controls.dispose();
            renderer.dispose();
        }, { once: true });
    } catch {
        root.dataset.viewerError = 'true';
        root.setAttribute('aria-busy', 'false');
        status.textContent = 'The interactive view is unavailable. The product image remains available below.';
        raiseButton.disabled = true;
        lowerButton.disabled = true;
    }
}

const observe = (root: ViewerRoot) => {
    if (!('IntersectionObserver' in window)) {
        void mount(root);
        return;
    }
    const observer = new IntersectionObserver((entries) => {
        if (!entries.some((entry) => entry.isIntersecting)) return;
        observer.disconnect();
        void mount(root);
    }, { rootMargin: '240px 0px' });
    observer.observe(root);
};

document.querySelectorAll<ViewerRoot>('[data-everbranch-product-viewer]').forEach(observe);
