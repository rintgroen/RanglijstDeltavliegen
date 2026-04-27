(function () {
  function setupMemoryShowcase(showcase) {
    var slides = Array.prototype.slice.call(showcase.querySelectorAll('.memory-slide'));
    if (slides.length === 0) {
      return;
    }

    var current = 0;
    var visibleMs = 7800;
    var gapMs = 320;
    var timer = null;
    var progress = showcase.querySelector('.memory-progress span');

    function restartTypewriter(slide) {
      var text = slide.querySelector('.memory-copy p');
      if (!text) {
        return;
      }
      text.style.animation = 'none';
      text.offsetHeight;
      text.style.animation = '';
    }

    function show(index) {
      slides.forEach(function (slide, slideIndex) {
        slide.classList.toggle('is-active', slideIndex === index);
      });
      restartProgress();
      restartTypewriter(slides[index]);
    }

    function restartProgress() {
      if (!progress) {
        return;
      }
      showcase.classList.remove('is-running');
      progress.style.animation = 'none';
      progress.offsetHeight;
      progress.style.animation = '';
      showcase.classList.add('is-running');
    }

    function scheduleNext() {
      if (slides.length < 2) {
        return;
      }
      timer = window.setTimeout(function () {
        slides[current].classList.remove('is-active');
        timer = window.setTimeout(function () {
          current = (current + 1) % slides.length;
          show(current);
          scheduleNext();
        }, gapMs);
      }, visibleMs);
    }

    showcase.classList.add('is-ready');
    show(current);

    if (slides.length > 1 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      scheduleNext();
      document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
          window.clearTimeout(timer);
          timer = null;
          showcase.classList.remove('is-running');
        } else if (timer === null) {
          restartProgress();
          scheduleNext();
        }
      });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.memory-showcase').forEach(setupMemoryShowcase);
  });
})();
