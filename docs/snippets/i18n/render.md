The PHP server SDK has no `t()` — labels render in the browser via the client
SDK. After the loader tag (see setup) is in the `<head>`, render in the browser:

```js
// browser, @shipeasy/sdk/client
import { t } from "@shipeasy/sdk/client";
element.textContent = t("checkout.cta");   // resolves from the {{PROFILE}} profile
```
