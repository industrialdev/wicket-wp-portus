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

  form.addEventListener("submit", (event) => {
    const submitEvent = event;
    const submitter = submitEvent.submitter;
    const activeElement = document.activeElement;
    const isExportSubmitter =
      submitter instanceof HTMLButtonElement
        ? submitter.name === "hf_export_submit"
        : activeElement instanceof HTMLButtonElement &&
          activeElement.name === "hf_export_submit";

    if (!isExportSubmitter) {
      return;
    }

    event.preventDefault();
    void audio.play().catch(() => {});

    window.setTimeout(() => {
      form.submit();
    }, 120);
  });
})();
