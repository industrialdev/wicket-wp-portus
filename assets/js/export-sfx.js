(() => {
  const config = window.wicketPortusExportSfx || {};
  const audioUrl = typeof config.audioUrl === "string" ? config.audioUrl : "";

  if (audioUrl === "") {
    return;
  }

  const exportButton = document.querySelector('button[name="hf_export_submit"]');
  if (!(exportButton instanceof HTMLButtonElement)) {
    return;
  }

  const form = exportButton.closest("form");
  if (!(form instanceof HTMLFormElement)) {
    return;
  }

  const audio = new Audio(audioUrl);
  audio.preload = "auto";
  let allowNativeClick = false;
  let inFlight = false;
  const disableExportButton = () => {
    exportButton.disabled = true;
    exportButton.setAttribute("aria-disabled", "true");
  };
  const enableExportButton = () => {
    exportButton.disabled = false;
    exportButton.setAttribute("aria-disabled", "false");
  };

  const submitForm = () => {
    enableExportButton();
    allowNativeClick = true;
    if (typeof form.requestSubmit === "function") {
      form.requestSubmit(exportButton);
      return;
    }

    // Fallback preserves submit intent for environments without requestSubmit.
    const hiddenSubmit = document.createElement("input");
    hiddenSubmit.type = "hidden";
    hiddenSubmit.name = exportButton.name;
    hiddenSubmit.value = exportButton.value;
    form.appendChild(hiddenSubmit);
    form.submit();
    hiddenSubmit.remove();
  };

  const expectedPlaybackMs = () => 1850;

  // --- Particle effect ---
  const PARTICLE_COLORS = [
    "#FFD700", // gold
    "#C084FC", // violet
    "#818CF8", // indigo
    "#FFFFFF", // white
    "#FCD34D", // amber
    "#60A5FA", // blue
    "#F472B6", // pink
  ];

  const injectParticleStyles = () => {
    if (document.getElementById("wicket-portus-particle-styles")) {
      return;
    }
    const style = document.createElement("style");
    style.id = "wicket-portus-particle-styles";
    style.textContent = `
      @keyframes portus-particle-fly {
        0%   { transform: translate(0, 0) scale(1) rotate(0deg); opacity: 1; }
        60%  { opacity: 1; }
        100% { transform: translate(var(--px), var(--py)) scale(0) rotate(var(--rot)); opacity: 0; }
      }
      .portus-particle {
        position: fixed;
        pointer-events: none;
        z-index: 99999;
        animation: portus-particle-fly var(--dur) ease-out forwards;
      }
      .portus-particle-dot {
        border-radius: 50%;
      }
      .portus-particle-star {
        clip-path: polygon(50% 0%, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%);
      }
    `;
    document.head.appendChild(style);
  };

  const spawnParticles = (button) => {
    injectParticleStyles();
    const rect = button.getBoundingClientRect();
    const cx = rect.left + rect.width / 2;
    const cy = rect.top + rect.height / 2;
    const count = 80;

    for (let i = 0; i < count; i++) {
      const angle = Math.random() * 2 * Math.PI;
      const distance = 80 + Math.random() * 220;
      const px = Math.cos(angle) * distance;
      const py = Math.sin(angle) * distance;
      const color = PARTICLE_COLORS[Math.floor(Math.random() * PARTICLE_COLORS.length)];
      const dur = 900 + Math.random() * 900;
      const size = 8 + Math.random() * 12;
      const rot = (Math.random() - 0.5) * 720;
      const isStar = Math.random() < 0.35;

      const el = document.createElement("div");
      el.className = "portus-particle " + (isStar ? "portus-particle-star" : "portus-particle-dot");
      el.style.cssText = [
        `left:${cx - size / 2}px`,
        `top:${cy - size / 2}px`,
        `width:${size}px`,
        `height:${size}px`,
        `background:${color}`,
        `box-shadow:0 0 ${size + 6}px ${size}px ${color}`,
        `--px:${px}px`,
        `--py:${py}px`,
        `--dur:${dur}ms`,
        `--rot:${rot}deg`,
      ].join(";");

      document.body.appendChild(el);
      window.setTimeout(() => el.remove(), dur + 50);
    }
  };
  // --- End particle effect ---

  exportButton.addEventListener("click", (event) => {
    if (allowNativeClick) {
      allowNativeClick = false;
      return;
    }

    if (inFlight) {
      event.preventDefault();
      return;
    }

    inFlight = true;
    disableExportButton();
    event.preventDefault();
    spawnParticles(exportButton);
    audio.currentTime = 0;
    const playResult = audio.play();
    const finish = () => {
      if (!inFlight) {
        return;
      }
      inFlight = false;
      submitForm();
    };

    const onEnded = () => {
      window.clearTimeout(safetyTimer);
      finish();
    };

    const safetyTimer = window.setTimeout(() => {
      audio.removeEventListener("ended", onEnded);
      finish();
    }, expectedPlaybackMs());

    audio.addEventListener("ended", onEnded, { once: true });

    if (playResult && typeof playResult.then === "function") {
      playResult.catch(() => {
        audio.removeEventListener("ended", onEnded);
        window.clearTimeout(safetyTimer);
        finish();
      });
    }
  });
})();
