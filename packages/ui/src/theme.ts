/** Put in <head> so the chosen theme is set before the first paint. The header's switch stores the choice. */
export const themeScript = `(function(){try{var t=localStorage.getItem("cn-theme");if(t==="dark"||(!t&&matchMedia("(prefers-color-scheme: dark)").matches))document.documentElement.classList.add("cn-dark")}catch(e){}})()`;
