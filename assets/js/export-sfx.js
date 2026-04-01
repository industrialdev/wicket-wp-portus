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
