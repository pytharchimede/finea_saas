const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const context={window:{},URL,document:{currentScript:{src:'https://finea.test/assets/js/supplier-fne.js'},readyState:'complete',querySelectorAll:()=>[]}};
vm.runInNewContext(fs.readFileSync(__dirname+'/../assets/js/supplier-fne.js','utf8'),context);
const spec=context.window.fineaSupplierIdentitySpec;
for(const [country,method,field,label] of [
 ['CI','fne','ncc','NCC'],['CI','manual','ncc','NCC'],['FR','fr','siret','SIRET'],
 ['DE','vies','vat_number','TVA'],['IE','vies','vat_number','TVA'],
 ['US','sec','registration_number','CIK'],['US','manual','registration_number','américain'],
 ['CH','manual','registration_number','locale'],['CI','exception',null,'Dérogation']
]) {assert.equal(spec(country,method).field,field);assert.ok(spec(country,method).label.includes(label));}
assert.ok(spec('GR','vies').placeholder.includes('EL'));
console.log('SupplierIdentityLookupTest: OK (NCC, SIRET, TVA, CIK, local and exception)');
