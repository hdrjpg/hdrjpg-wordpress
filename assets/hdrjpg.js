var hdrjpg = (() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __export = (target, all) => {
    for (var name in all)
      __defProp(target, name, { get: all[name], enumerable: true });
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

  // src/hdrjpg.js
  var hdrjpg_exports = {};
  __export(hdrjpg_exports, {
    apply: () => apply,
    currentEnvironment: () => currentEnvironment,
    needsVideo: () => needsVideo,
    replaceWithVideo: () => replaceWithVideo
  });
  function needsVideo({ userAgent, maxTouchPoints, isHdrScreen, isDynamicRangeLimitSupported }) {
    if (!isHdrScreen) {
      return false;
    }
    if (/\bFirefox\//.test(userAgent) && !/\bSeamonkey\//.test(userAgent)) {
      return true;
    }
    const isIos = /\b(iPhone|iPad|iPod)\b/.test(userAgent) || /\bMacintosh\b/.test(userAgent) && maxTouchPoints > 1;
    const isWebKitOnly = /\bAppleWebKit\//.test(userAgent) && !/\b(Chrome|Chromium|Edg|OPR|SamsungBrowser)\//.test(userAgent);
    if (isIos) {
      return !isDynamicRangeLimitSupported;
    }
    if (isWebKitOnly) {
      return true;
    }
    return false;
  }
  function currentEnvironment() {
    return {
      userAgent: navigator.userAgent,
      maxTouchPoints: navigator.maxTouchPoints || 0,
      isHdrScreen: window.matchMedia("(dynamic-range: high)").matches,
      isDynamicRangeLimitSupported: !!(window.CSS && CSS.supports("dynamic-range-limit", "no-limit"))
    };
  }
  function replaceWithVideo(element) {
    const picture = element.querySelector("picture");
    if (!picture || !element.dataset.mp4Src) {
      return;
    }
    const img = picture.querySelector("img");
    const video = document.createElement("video");
    video.muted = true;
    video.defaultMuted = true;
    video.playsInline = true;
    video.autoplay = true;
    video.preload = "auto";
    video.disablePictureInPicture = true;
    video.disableRemotePlayback = true;
    video.setAttribute("muted", "");
    video.setAttribute("playsinline", "");
    if (img) {
      if (img.alt) {
        video.setAttribute("role", "img");
        video.setAttribute("aria-label", img.alt);
      }
      for (const attribute of ["width", "height", "class", "style", "draggable"]) {
        if (img.hasAttribute(attribute)) {
          video.setAttribute(attribute, img.getAttribute(attribute));
        }
      }
    }
    video.src = element.dataset.mp4Src;
    picture.replaceWith(video);
    element.dataset.hdrjpgVideo = "";
  }
  function apply(root = document, environment = currentEnvironment()) {
    if (!needsVideo(environment)) {
      return 0;
    }
    let count = 0;
    root.querySelectorAll(".hdrjpg[data-mp4-src]:not([data-hdrjpg-video])").forEach((element) => {
      replaceWithVideo(element);
      count++;
    });
    return count;
  }
  if (typeof document !== "undefined") {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", () => apply());
    } else {
      apply();
    }
  }
  return __toCommonJS(hdrjpg_exports);
})();
