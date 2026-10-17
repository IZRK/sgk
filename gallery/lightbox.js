(() => {
  const dialog = document.getElementById('photo-lightbox');
  const links = Array.from(document.querySelectorAll('.photo-gallery-image[data-lightbox-index]'));
  if (!dialog || typeof dialog.showModal !== 'function' || links.length === 0) return;

  const mainImage = document.getElementById('photo-lightbox-image');
  const groupLabel = document.getElementById('photo-lightbox-group');
  const creditLabel = document.getElementById('photo-lightbox-credit');
  const awardLabel = document.getElementById('photo-lightbox-award');
  const positionLabel = document.getElementById('photo-lightbox-position');
  const closeButton = dialog.querySelector('.photo-lightbox-close');
  const previousButton = document.getElementById('photo-lightbox-previous');
  const nextButton = document.getElementById('photo-lightbox-next');
  const thumbnailStrip = dialog.querySelector('.photo-lightbox-thumbnails');
  const thumbnailButtons = Array.from(dialog.querySelectorAll('[data-lightbox-thumbnail]'));
  const photos = links.map((link) => ({
    url: link.href,
    photographer: link.dataset.photographer || '',
    group: link.dataset.group || '',
    award: link.dataset.award || '',
  }));

  let currentIndex = 0;
  let opener = null;

  const preload = (photo) => {
    if (!photo) return;
    const image = new Image();
    image.src = photo.url;
  };

  const showPhoto = (index) => {
    currentIndex = (index + photos.length) % photos.length;
    const photo = photos[currentIndex];
    mainImage.src = photo.url;
    mainImage.alt = `${photo.group}, foto: ${photo.photographer}${photo.award ? `, ${photo.award}` : ''}`;
    groupLabel.textContent = photo.group;
    creditLabel.textContent = `Foto: ${photo.photographer}`;
    awardLabel.textContent = photo.award;
    awardLabel.hidden = !photo.award;
    positionLabel.textContent = `${currentIndex + 1} / ${photos.length}`;

    thumbnailButtons.forEach((button) => {
      const active = Number(button.dataset.lightboxThumbnail) === currentIndex;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-current', active ? 'true' : 'false');
      if (active) {
        button.scrollIntoView({
          block: 'nearest',
          inline: 'center',
          behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
        });
      }
    });

    preload(photos[(currentIndex + 1) % photos.length]);
    preload(photos[(currentIndex - 1 + photos.length) % photos.length]);
  };

  links.forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      opener = link;
      dialog.showModal();
      showPhoto(Number(link.dataset.lightboxIndex));
      closeButton.focus();
    });
  });

  previousButton.addEventListener('click', () => showPhoto(currentIndex - 1));
  nextButton.addEventListener('click', () => showPhoto(currentIndex + 1));
  closeButton.addEventListener('click', () => dialog.close());
  thumbnailStrip.addEventListener('click', (event) => {
    const button = event.target.closest('[data-lightbox-thumbnail]');
    if (button) showPhoto(Number(button.dataset.lightboxThumbnail));
  });

  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });

  dialog.addEventListener('close', () => {
    opener?.focus({ preventScroll: true });
    opener = null;
  });

  dialog.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowLeft') {
      event.preventDefault();
      showPhoto(currentIndex - 1);
    } else if (event.key === 'ArrowRight') {
      event.preventDefault();
      showPhoto(currentIndex + 1);
    } else if (event.key === 'Home') {
      event.preventDefault();
      showPhoto(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      showPhoto(photos.length - 1);
    }
  });
})();
