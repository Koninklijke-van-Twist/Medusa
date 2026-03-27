<?php

if (!function_exists('render_loading_screen')) {
    function render_loading_screen(array $options = []): string
    {
        $id = (string) ($options['id'] ?? 'app-loading-screen');
        $title = (string) ($options['title'] ?? 'Gegevens laden...');
        $subtitle = (string) ($options['subtitle'] ?? 'Even geduld aub');
        $visible = (bool) ($options['visible'] ?? false);
        $redirectUrl = (string) ($options['redirectUrl'] ?? '');
        $redirectDelayMs = max(0, (int) ($options['redirectDelayMs'] ?? 900));

        $canvasId = $id . '-canvas';
        $isVisibleClass = $visible ? ' is-visible' : '';

        ob_start();
        ?>
        <div id="<?= htmlspecialchars($id) ?>" class="snake-loader<?= htmlspecialchars($isVisibleClass) ?>" aria-hidden="true">
            <canvas id="<?= htmlspecialchars($canvasId) ?>" class="snake-loader-bg"></canvas>
            <div class="snake-loader-overlay"></div>
            <div class="snake-loader-card" role="status" aria-live="polite">
                <div class="snake-loader-spinner"></div>
                <div class="snake-loader-title"><?= htmlspecialchars($title) ?></div>
                <div class="snake-loader-subtitle"><?= htmlspecialchars($subtitle) ?></div>
            </div>
        </div>

        <style>
            .snake-loader {
                position: fixed;
                inset: 0;
                z-index: 2200;
                display: none;
            }

            .snake-loader.is-visible {
                display: block;
            }

            .snake-loader-bg,
            .snake-loader-overlay {
                position: absolute;
                inset: 0;
            }

            .snake-loader-bg {
                width: 100%;
                height: 100%;
                background: #0b1220;
            }

            .snake-loader-overlay {
                background: radial-gradient(circle at 50% 50%, rgba(11, 18, 32, 0.10) 0%, rgba(11, 18, 32, 0.70) 70%);
            }

            .snake-loader-card {
                position: absolute;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                min-width: 260px;
                padding: 22px 24px;
                border-radius: 18px;
                background: rgba(255, 255, 255, 0.95);
                border: 1px solid #e2e8f0;
                box-shadow: 0 16px 45px rgba(15, 23, 42, 0.28);
                text-align: center;
            }

            .snake-loader-spinner {
                width: 34px;
                height: 34px;
                margin: 0 auto 10px;
                border-radius: 50%;
                border: 4px solid #cbd5e1;
                border-top-color: #2563eb;
                animation: snake-loader-spin 0.9s linear infinite;
            }

            .snake-loader-title {
                font-weight: 700;
                color: #0f172a;
                margin-bottom: 4px;
            }

            .snake-loader-subtitle {
                color: #475569;
                font-size: 13px;
            }

            @keyframes snake-loader-spin {
                to {
                    transform: rotate(360deg);
                }
            }
        </style>

        <script>
            (function () {
                const loader = document.getElementById(<?= json_encode($id) ?>);
                const canvas = document.getElementById(<?= json_encode($canvasId) ?>);
                if (!loader || !canvas) {
                    return;
                }

                const ctx = canvas.getContext('2d');
                const cell = 20;
                let cols = 0;
                let rows = 0;

                let snake = [];
                let food = { x: 0, y: 0 };
                let path = [];
                let tickTimer = null;
                let pathPlannedForFood = false;

                function isFoodBlockedByCard(x, y) {
                    const card = loader.querySelector('.snake-loader-card');
                    if (!card) {
                        return false;
                    }

                    const cardRect = card.getBoundingClientRect();
                    const canvasRect = canvas.getBoundingClientRect();
                    if (canvasRect.width <= 0 || canvasRect.height <= 0) {
                        return false;
                    }

                    const margin = 1; // 1 cel marge rond het kaartje
                    const leftCell = Math.max(0, Math.floor((cardRect.left - canvasRect.left) / cell) - margin);
                    const rightCell = Math.min(cols - 1, Math.floor((cardRect.right - canvasRect.left) / cell) + margin);
                    const topCell = Math.max(0, Math.floor((cardRect.top - canvasRect.top) / cell) - margin);
                    const bottomCell = Math.min(rows - 1, Math.floor((cardRect.bottom - canvasRect.top) / cell) + margin);

                    return x >= leftCell && x <= rightCell && y >= topCell && y <= bottomCell;
                }

                function keyForPoint(point) {
                    return point.x + ',' + point.y;
                }

                function bodyKey(body) {
                    return body.map(keyForPoint).join('|');
                }

                function inBounds(x, y) {
                    return x >= 0 && y >= 0 && x < cols && y < rows;
                }

                function resizeCanvas() {
                    const dpr = Math.max(1, Math.min(2, window.devicePixelRatio || 1));
                    const width = Math.max(window.innerWidth, 320);
                    const height = Math.max(window.innerHeight, 220);

                    canvas.width = Math.floor(width * dpr);
                    canvas.height = Math.floor(height * dpr);
                    canvas.style.width = width + 'px';
                    canvas.style.height = height + 'px';
                    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

                    cols = Math.max(14, Math.floor(width / cell));
                    rows = Math.max(10, Math.floor(height / cell));
                }

                function randomFood(excludeBody) {
                    const taken = new Set(excludeBody.map(keyForPoint));
                    let candidate = { x: 0, y: 0 };
                    let attempts = 0;

                    do {
                        candidate = {
                            x: Math.floor(Math.random() * cols),
                            y: Math.floor(Math.random() * rows)
                        };
                        attempts++;
                        if (attempts > 5000) {
                            break;
                        }
                    } while (taken.has(keyForPoint(candidate)) || isFoodBlockedByCard(candidate.x, candidate.y));

                    return candidate;
                }

                function createInitialSnake() {
                    const centerX = Math.floor(cols / 2);
                    const centerY = Math.floor(rows / 2);
                    const initialTailBlocks = 5;
                    const body = [];

                    for (let i = 0; i <= initialTailBlocks; i++) {
                        body.push({ x: centerX - i, y: centerY });
                    }

                    return body;
                }

                function reconstructPath(node) {
                    const full = [];
                    let current = node;
                    while (current) {
                        full.push({ x: current.x, y: current.y });
                        current = current.parent;
                    }
                    full.reverse();
                    return full.slice(1);
                }

                function findAStarPath(startBody, target) {
                    const start = {
                        x: startBody[0].x,
                        y: startBody[0].y,
                        g: 0,
                        h: Math.abs(startBody[0].x - target.x) + Math.abs(startBody[0].y - target.y),
                        f: 0,
                        body: startBody.map(p => ({ x: p.x, y: p.y })),
                        parent: null,
                    };
                    start.f = start.g + start.h;

                    const open = [start];
                    const visitedBestG = new Map();
                    visitedBestG.set(start.x + ',' + start.y + '|' + bodyKey(start.body), 0);

                    const directions = [
                        { x: 1, y: 0 },
                        { x: -1, y: 0 },
                        { x: 0, y: 1 },
                        { x: 0, y: -1 },
                    ];

                    let loops = 0;
                    const maxLoops = 12000;

                    while (open.length && loops < maxLoops) {
                        loops++;
                        let bestIndex = 0;
                        for (let i = 1; i < open.length; i++) {
                            if (open[i].f < open[bestIndex].f) {
                                bestIndex = i;
                            }
                        }
                        const current = open.splice(bestIndex, 1)[0];
                        if (!current) {
                            break;
                        }

                        if (current.x === target.x && current.y === target.y) {
                            return reconstructPath(current);
                        }

                        for (const dir of directions) {
                            const nx = current.x + dir.x;
                            const ny = current.y + dir.y;
                            if (!inBounds(nx, ny)) {
                                continue;
                            }

                            const grows = (nx === target.x && ny === target.y);
                            const nextBody = [{ x: nx, y: ny }, ...current.body.map(p => ({ x: p.x, y: p.y }))];
                            if (!grows) {
                                nextBody.pop();
                            }

                            const occupied = new Set();
                            let collision = false;
                            for (const part of nextBody) {
                                const partKey = keyForPoint(part);
                                if (occupied.has(partKey)) {
                                    collision = true;
                                    break;
                                }
                                occupied.add(partKey);
                            }
                            if (collision) {
                                continue;
                            }

                            const g = current.g + 1;
                            const h = Math.abs(nx - target.x) + Math.abs(ny - target.y);
                            const stateKey = nx + ',' + ny + '|' + bodyKey(nextBody);
                            const bestKnown = visitedBestG.get(stateKey);
                            if (bestKnown !== undefined && bestKnown <= g) {
                                continue;
                            }
                            visitedBestG.set(stateKey, g);

                            open.push({
                                x: nx,
                                y: ny,
                                g: g,
                                h: h,
                                f: g + h,
                                body: nextBody,
                                parent: current,
                            });
                        }
                    }

                    return [];
                }

                function safeFallbackStep() {
                    const head = snake[0];
                    const directions = [
                        { x: 1, y: 0 },
                        { x: -1, y: 0 },
                        { x: 0, y: 1 },
                        { x: 0, y: -1 },
                    ];

                    const candidates = [];
                    for (const dir of directions) {
                        const nx = head.x + dir.x;
                        const ny = head.y + dir.y;
                        if (!inBounds(nx, ny)) {
                            continue;
                        }

                        const testBody = [{ x: nx, y: ny }, ...snake.map(p => ({ x: p.x, y: p.y }))];
                        testBody.pop();

                        let collision = false;
                        const occupancy = new Set();
                        for (const part of testBody) {
                            const partKey = keyForPoint(part);
                            if (occupancy.has(partKey)) {
                                collision = true;
                                break;
                            }
                            occupancy.add(partKey);
                        }
                        if (collision) {
                            continue;
                        }

                        const score = Math.abs(nx - food.x) + Math.abs(ny - food.y);
                        candidates.push({ x: nx, y: ny, score: score });
                    }

                    if (!candidates.length) {
                        return null;
                    }

                    candidates.sort((a, b) => a.score - b.score);
                    return { x: candidates[0].x, y: candidates[0].y };
                }

                function planPath() {
                    path = findAStarPath(snake, food);
                    pathPlannedForFood = true;
                }

                function moveSnake(nextHead) {
                    const grows = (nextHead.x === food.x && nextHead.y === food.y);
                    snake.unshift(nextHead);
                    if (!grows) {
                        snake.pop();
                    }

                    if (grows) {
                        food = randomFood(snake);
                        path = [];
                        pathPlannedForFood = false;
                    }
                }

                function draw() {
                    const width = canvas.clientWidth;
                    const height = canvas.clientHeight;

                    ctx.fillStyle = '#0b1220';
                    ctx.fillRect(0, 0, width, height);

                    ctx.strokeStyle = 'rgba(148, 163, 184, 0.08)';
                    ctx.lineWidth = 1;
                    for (let x = 0; x <= cols; x++) {
                        ctx.beginPath();
                        ctx.moveTo(x * cell + 0.5, 0);
                        ctx.lineTo(x * cell + 0.5, rows * cell);
                        ctx.stroke();
                    }
                    for (let y = 0; y <= rows; y++) {
                        ctx.beginPath();
                        ctx.moveTo(0, y * cell + 0.5);
                        ctx.lineTo(cols * cell, y * cell + 0.5);
                        ctx.stroke();
                    }

                    ctx.fillStyle = '#ef4444';
                    ctx.fillRect(food.x * cell + 2, food.y * cell + 2, cell - 4, cell - 4);

                    snake.forEach((part, idx) => {
                        ctx.fillStyle = idx === 0 ? '#38bdf8' : '#22d3ee';
                        ctx.fillRect(part.x * cell + 2, part.y * cell + 2, cell - 4, cell - 4);
                    });
                }

                function tick() {
                    if (!loader.classList.contains('is-visible')) {
                        return;
                    }

                    // Bereken maximaal 1x per voedselpositie; daarna alleen het pad volgen.
                    if (!path.length && !pathPlannedForFood) {
                        planPath();
                    }

                    let next = null;
                    if (path.length) {
                        next = path.shift();
                    } else {
                        next = safeFallbackStep();
                    }

                    if (!next) {
                        snake = createInitialSnake();
                        food = randomFood(snake);
                        path = [];
                        pathPlannedForFood = false;
                        return;
                    }

                    moveSnake(next);
                    draw();
                }

                function startAnimation() {
                    if (tickTimer) {
                        return;
                    }
                    tickTimer = window.setInterval(tick, 85);
                }

                function initializeBoard() {
                    resizeCanvas();
                    snake = createInitialSnake();
                    food = randomFood(snake);
                    path = [];
                    pathPlannedForFood = false;
                    draw();
                    startAnimation();
                }

                if (!window.showLoadingScreen) {
                    window.showLoadingScreen = function (targetId) {
                        const id = targetId || 'app-loading-screen';
                        const el = document.getElementById(id);
                        if (!el) {
                            return;
                        }
                        el.classList.add('is-visible');
                        document.body.style.overflow = 'hidden';
                    };
                }

                if (!window.hideLoadingScreen) {
                    window.hideLoadingScreen = function (targetId) {
                        const id = targetId || 'app-loading-screen';
                        const el = document.getElementById(id);
                        if (!el) {
                            return;
                        }
                        el.classList.remove('is-visible');
                        document.body.style.overflow = '';
                    };
                }

                initializeBoard();
                window.addEventListener('resize', resizeCanvas);

                const redirectUrl = <?= json_encode($redirectUrl) ?>;
                const redirectDelayMs = <?= (int) $redirectDelayMs ?>;
                if (redirectUrl) {
                    window.setTimeout(function () {
                        window.location.href = redirectUrl;
                    }, redirectDelayMs);
                }
            })();
        </script>
        <?php

        return (string) ob_get_clean();
    }
}
