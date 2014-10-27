vmShippingBySku
===============

This plugin allows the specification of shipping prices based on location and SKU number for Virtuemart 2+.

## Configuration

Configuration is done manually. I have not made a custom Joomla field for it yet.

Rules for individual products base on their SKU numbers. This is to be in CSV format with columns: SKU begins with, SKU ends with, SKU matches (regex), Quantities/Fees. 

Each row constitutes a rule. They are processed from top to bottom and the Quantities/Fees box is processed from left to right. SKU begins with, SKU ends with, SKU matches constitue conditions that must be met for that rule to apply to a certain item. All three off these conditions must be met for the rule to apply. However, if one of these conditions is not necessary, it can be left blank. Once a SKU matching a rule, the remaining rules are not processed. That being the case, rules should be listed in order of importance. The same goes for the Quantities/Fees.

The SKU begins/ends conditions do exactly as they say. If the (case-sensitive) characters entered there match the beginning of ending of a SKU number (respecitively), the conditions are met. These are case sensitive fields.

The SKU matches columnn uses REGEX for matching a sku; it can be used for more complicated matching or to reduce the number of rules. It is generally only for advanced users. It should include beginnning and ending deliminators and flags (e.g. /^L/i, /a[\-\.]\d+$/i).

The final column, Quantities/Fees, is used to specify how much to charge for shipping when the SKU rules are met. Different prices can be charged for different quantities. The basic structure is {minimun quantity}-{maximum quantity}/{price charged}. To represent any arbitrarily large quantity, use inf as the quantity. The simpliest configuration here is: 1-inf/7.00 . This setting will charge any item that matching the rules $7.00. A more complicated example is: 1-2/3.00 3-10/2.00 11-inf/1.00 . This setting will charge $3.00 for the first two items bought that match this rule, $2.00 for the next 8 items that match this rule, and $1.00 for any quantity of items over 10.

It is recomended that this configuration is created in a spread sheet program (like Excel or Libre Offic Calc) and saved in CSV format to get correct formatting. This CSV file should be opened with a text editor and the contents copied to this box. Strings should be surrounded by double quotes, columns sould be seperated by commas, and rows should be seperated by new lines.

## Example 1

Products beginning with "l-" will be $65 for the first item and $25 for each additional product

"","","/^l\-/i","1-1/65 2-inf/25","Product 1"

## Example 2 

Products beginning with "am-" will be $15 for the first 10 products and $8 for each additional 10 products.

"","","/^am\-/i","(1-10)/15 (11-20)+/8","Product 2"
