gamp-php
========

PHP Interface for Sending Data to Google Analytics via the Measurement Protocol. 

This is very useful for scenarios where the data you want to submit does not naturally occur in a client-side/browser/js context, and/or where the most straightforward place to capture data (events, user dimensions, etc.) is in PHP code.

Simple example usage:

    $gamp = new GAMP();
    $gamp->sendEvent(array(
      'action' => 'click',
      'category' => 'slideshow',
      'label' => 'Homepage Slideshow',
    ));

For more info, see Google's [development guide](https://developers.google.com/analytics/devguides/collection/protocol/v1/devguide) and [parameter reference](https://developers.google.com/analytics/devguides/collection/protocol/v1/parameters) for the protocol.
