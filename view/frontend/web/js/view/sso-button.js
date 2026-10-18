/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
define([
    'uiComponent'
], function (Component) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'DmLab_CustomerSso/sso-button',
            startUrl: '',
            label: '',
            iconUrl: null
        }
    });
});
